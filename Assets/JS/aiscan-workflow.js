/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

'use strict';

(() => {
    // ── State ──────────────────────────────────────────────────────────
    const state = {
        documents: [],
        currentIndex: 0,
        importMode: null,
        useHistory: false,
        availableProviders: [],
        defaultProvider: null,
        extractionPrompt: null,
        maxParallelRequests: 5,
        selectedIndices: new Set(),
        sortField: 'upload_order',
    };

    const STATUS = {
        PENDING: 'pending',
        ANALYZING: 'analyzing',
        ANALYZED: 'analyzed',
        NEEDS_REVIEW: 'needs_review',
        DISCARDED: 'discarded',
        READY: 'ready',
        IMPORTED: 'imported',
        FAILED: 'failed',
    };

    const STATUS_LABELS = {
        pending: {cls: 'text-bg-secondary', icon: 'fa-clock'},
        analyzing: {cls: 'text-bg-info', icon: 'fa-spinner fa-spin'},
        analyzed: {cls: 'text-bg-primary', icon: 'fa-check'},
        needs_review: {cls: 'text-bg-warning', icon: 'fa-exclamation-triangle'},
        discarded: {cls: 'text-bg-dark', icon: 'fa-ban'},
        ready: {cls: 'text-bg-success', icon: 'fa-check-circle'},
        imported: {cls: 'text-bg-success', icon: 'fa-file-invoice'},
        failed: {cls: 'text-bg-danger', icon: 'fa-times-circle'},
    };

    // ── Helpers ────────────────────────────────────────────────────────

    function trans(key, replacements) {
        let value = key;
        if (window.i18n && typeof window.i18n.trans === 'function') {
            value = window.i18n.trans(key);
        }
        if (value === key && window.aiscanI18n && window.aiscanI18n[key]) {
            value = window.aiscanI18n[key];
        }
        if (value === key) {
            return key;
        }
        if (replacements) {
            Object.entries(replacements).forEach(([k, v]) => {
                value = value.replaceAll(k, v == null ? '' : String(v));
            });
        }
        return value;
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/"/g, '&quot;');
    }

    function checkInBatchDuplicate(doc) {
        const inv = doc.extractedData?.invoice;
        if (!inv?.number) {
            return null;
        }
        const supplierId = doc.extractedData?.supplier?.matched_supplier_id || '';
        for (const other of state.documents) {
            if (other === doc || !other.extractedData?.invoice?.number) {
                continue;
            }
            const otherInv = other.extractedData.invoice;
            const otherSupplier = other.extractedData.supplier?.matched_supplier_id || '';
            if (
                otherInv.number === inv.number
                && otherInv.issue_date === inv.issue_date
                && otherSupplier === supplierId
                && Math.abs((otherInv.total || 0) - (inv.total || 0)) < 0.02
                && (other.status === STATUS.ANALYZED || other.status === STATUS.READY || other.status === STATUS.NEEDS_REVIEW)
            ) {
                return trans('aiscan-duplicate-in-batch', {'%name%': other.originalName});
            }
        }
        return null;
    }

    function currentDoc() {
        return state.documents[state.currentIndex] || null;
    }

    // ── Initialization ─────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const providerSelect = document.getElementById('aiscan-provider-select');
        if (providerSelect && providerSelect.value) {
            state.defaultProvider = providerSelect.value;
            state.availableProviders = Array.from(providerSelect.options)
                .filter(o => o.value)
                .map(o => o.value);
        }
        bindUploadStep();
        bindReviewStep();
        bindImportStep();
        bindSplitHandle();
    }

    // ── Step 1: Upload ─────────────────────────────────────────────────

    function bindUploadStep() {
        const dropZone = document.getElementById('aiscan-drop-zone');
        const fileInput = document.getElementById('aiscan-file-input');
        const uploadBtn = document.getElementById('aiscan-upload-btn');

        if (!dropZone || !fileInput) {
            return;
        }

        dropZone.addEventListener('click', () => fileInput.click());
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('aiscan-drag-over');
        });
        dropZone.addEventListener('dragleave', (e) => {
            if (!dropZone.contains(e.relatedTarget)) {
                dropZone.classList.remove('aiscan-drag-over');
            }
        });
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('aiscan-drag-over');
            if (e.dataTransfer.files.length > 0) {
                onFilesSelected(e.dataTransfer.files);
            }
        });

        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                onFilesSelected(fileInput.files);
            }
        });

        uploadBtn.addEventListener('click', startUploadAndAnalyze);

        document.querySelectorAll('input[name="import_mode"]').forEach(radio => {
            radio.addEventListener('change', () => {
                state.importMode = radio.value;
                document.getElementById('aiscan-import-mode-error')?.classList.add('d-none');
            });
        });

        const historyCheckbox = document.getElementById('use-history');
        if (historyCheckbox) {
            historyCheckbox.addEventListener('change', () => {
                state.useHistory = historyCheckbox.checked;
            });
        }
    }

    function onFilesSelected(fileList) {
        const files = Array.from(fileList);
        if (files.length === 0) {
            return;
        }

        state.documents = files.map((file, index) => ({
            index,
            file,
            originalName: file.name,
            mimeType: file.type,
            size: file.size,
            objectUrl: URL.createObjectURL(file),
            tmpFile: null,
            status: STATUS.PENDING,
            extractedData: null,
            error: null,
            reviewDecision: null,
        }));

        const dropZone = document.getElementById('aiscan-drop-zone');
        dropZone.innerHTML = `
            <div>
                <div class="fs-3 mb-2 text-success"><i class="fa-solid fa-check-circle"></i></div>
                <div class="fw-semibold">${escapeHtml(trans('aiscan-files-selected', {'%count%': String(files.length)}))}</div>
                <div class="small text-muted mt-2">${escapeHtml(trans('aiscan-drop-or-click'))}</div>
            </div>
        `;

        const fileListEl = document.getElementById('aiscan-file-list');
        const fileListBody = document.getElementById('aiscan-file-list-body');
        const fileCount = document.getElementById('aiscan-file-count');
        if (fileListEl && fileListBody) {
            fileListEl.classList.remove('d-none');
            if (fileCount) {
                fileCount.textContent = trans('aiscan-files-selected', {'%count%': String(files.length)});
            }
            fileListBody.innerHTML = files.map((f, i) => {
                const icon = f.type === 'application/pdf' ? 'fa-file-pdf text-danger' : 'fa-file-image text-primary';
                const sizeMb = (f.size / 1024 / 1024).toFixed(2);
                return `<div class="list-group-item py-1 px-2 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center min-width-0">
                        <i class="fa-solid ${icon} me-2"></i>
                        <span class="text-truncate small">${escapeHtml(f.name)}</span>
                    </div>
                    <span class="text-muted small ms-2" style="white-space:nowrap">${sizeMb} MB</span>
                </div>`;
            }).join('');
        }

        document.getElementById('aiscan-upload-btn').disabled = false;
        document.getElementById('aiscan-file-input').value = '';
    }

    async function startUploadAndAnalyze() {
        if (!state.importMode) {
            const errorEl = document.getElementById('aiscan-import-mode-error');
            if (errorEl) {
                errorEl.classList.remove('d-none');
            }
            return;
        }

        const uploadBtn = document.getElementById('aiscan-upload-btn');
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-1"></i>${escapeHtml(trans('aiscan-uploading-file'))}`;

        // Save user's provider choice BEFORE upload (buildProviderSelect will rebuild the dropdown)
        const userSelectedProvider = document.getElementById('aiscan-provider-select')?.value || state.defaultProvider;

        const formData = new FormData();
        state.documents.forEach(doc => formData.append('invoice_files[]', doc.file));

        try {
            const response = await fetch('AiScanInvoice?action=upload', {method: 'POST', body: formData});
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || trans('aiscan-no-file-uploaded'));
            }

            state.availableProviders = data.available_providers || [data.provider];
            state.extractionPrompt = data.extraction_prompt || '';
            state.maxParallelRequests = data.max_parallel_requests || 5;
            // Use the user's selection, not the server default
            state.defaultProvider = userSelectedProvider;

            const uploadedFiles = data.files || [];
            uploadedFiles.forEach(uf => {
                const doc = state.documents[uf.client_index];
                if (doc) {
                    doc.tmpFile = uf.tmp_file;
                    doc.mimeType = uf.mime_type || doc.mimeType;
                }
            });

            if (data.errors && data.errors.length > 0) {
                data.errors.forEach(err => {
                    const doc = state.documents[err.client_index];
                    if (doc) {
                        doc.status = STATUS.FAILED;
                        doc.error = err.error;
                    }
                });
            }

            buildProviderSelect();
            showStep('review');
            state.currentIndex = 0;
            renderCurrentDocument();
            analyzeAllPending();
        } catch (error) {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = `<i class="fa-solid fa-cloud-arrow-up me-1"></i>${escapeHtml(trans('aiscan-upload-and-analyze'))}`;
            alert(error.message);
        }
    }

    async function analyzeAllPending() {
        const provider = document.getElementById('aiscan-provider-select')?.value || state.defaultProvider;
        const concurrency = state.maxParallelRequests;
        const queue = state.documents.filter(d => d.tmpFile && d.status !== STATUS.FAILED);

        let running = 0;
        let nextIdx = 0;

        function refreshUI() {
            renderSidebar();
            const cur = currentDoc();
            if (cur && (cur.status === STATUS.ANALYZING || cur.status === STATUS.ANALYZED
                || cur.status === STATUS.NEEDS_REVIEW || cur.status === STATUS.FAILED)) {
                renderPreview(cur);
                renderReviewPanel(cur);
            }
        }

        async function analyzeOne(doc) {
            doc.status = STATUS.ANALYZING;
            refreshUI();

            try {
                const params = new URLSearchParams({
                    action: 'analyze',
                    tmp_file: doc.tmpFile,
                    mime_type: doc.mimeType,
                    import_mode: state.importMode,
                    use_history: state.useHistory ? '1' : '0',
                    supplier_id: '',
                    provider: provider,
                });

                const response = await fetch('AiScanInvoice?' + params.toString());
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                doc.extractedData = data.data;

                if (state.useHistory && doc.extractedData?.supplier?.matched_supplier_id && !doc._historyAnalyzed) {
                    doc._historyAnalyzed = true;
                    const reParams = new URLSearchParams({
                        action: 'analyze',
                        tmp_file: doc.tmpFile,
                        mime_type: doc.mimeType,
                        import_mode: state.importMode,
                        use_history: '1',
                        supplier_id: doc.extractedData.supplier.matched_supplier_id,
                        provider: provider,
                    });
                    const reResponse = await fetch('AiScanInvoice?' + reParams.toString());
                    const reData = await reResponse.json();
                    if (reData.success && reData.data) {
                        doc.extractedData = reData.data;
                    }
                }

                const warnings = doc.extractedData?._validation_errors || [];
                let needsReview = warnings.length > 0;

                // Server-side duplicate: invoice already exists in FS
                if (doc.extractedData?._duplicate) {
                    needsReview = true;
                }

                // In-batch duplicate: same invoice already analyzed in this batch
                const batchDup = checkInBatchDuplicate(doc);
                if (batchDup) {
                    doc.extractedData._validation_errors = warnings;
                    doc.extractedData._validation_errors.push(batchDup);
                    needsReview = true;
                }

                doc.status = needsReview ? STATUS.NEEDS_REVIEW : STATUS.ANALYZED;
            } catch (error) {
                doc.status = STATUS.FAILED;
                doc.error = error.message;
            }

            refreshUI();
        }

        return new Promise(resolve => {
            function scheduleNext() {
                while (running < concurrency && nextIdx < queue.length) {
                    const doc = queue[nextIdx++];
                    running++;
                    analyzeOne(doc).then(() => {
                        running--;
                        if (running === 0 && nextIdx >= queue.length) {
                            resolve();
                        } else {
                            scheduleNext();
                        }
                    });
                }
                if (queue.length === 0) {
                    resolve();
                }
            }
            scheduleNext();
        });
    }

    // ── Step 2: Review ─────────────────────────────────────────────────

    function bindReviewStep() {
        document.getElementById('aiscan-reanalyze-btn')?.addEventListener('click', reanalyzeCurrentDoc);
        document.getElementById('aiscan-discard-btn')?.addEventListener('click', discardCurrentDoc);

        document.getElementById('aiscan-sort-by')?.addEventListener('change', (e) => {
            state.sortField = e.target.value;
            renderSidebar();
        });

        document.getElementById('aiscan-select-all')?.addEventListener('change', (e) => {
            if (e.target.checked) {
                state.documents.forEach((_, i) => state.selectedIndices.add(i));
            } else {
                state.selectedIndices.clear();
            }
            renderSidebar();
        });

        document.getElementById('aiscan-bulk-apply')?.addEventListener('click', applyBulkAction);

        document.getElementById('aiscan-proceed-import-btn')?.addEventListener('click', () => {
            if (!state.importMode) {
                alert(trans('aiscan-import-mode-required'));
                return;
            }
            showStep('import');
            buildImportSummary();
        });
    }

    // ── Sidebar ────────────────────────────────────────────────────────

    function getSortedIndices() {
        const indices = state.documents.map((_, i) => i);
        const field = state.sortField;

        indices.sort((a, b) => {
            const da = state.documents[a];
            const db = state.documents[b];
            let va, vb;

            switch (field) {
                case 'supplier':
                    va = da.extractedData?.supplier?.name || '';
                    vb = db.extractedData?.supplier?.name || '';
                    break;
                case 'number':
                    va = da.extractedData?.invoice?.number || '';
                    vb = db.extractedData?.invoice?.number || '';
                    break;
                case 'date':
                    va = da.extractedData?.invoice?.issue_date || '';
                    vb = db.extractedData?.invoice?.issue_date || '';
                    break;
                case 'total':
                    va = parseFloat(da.extractedData?.invoice?.total) || 0;
                    vb = parseFloat(db.extractedData?.invoice?.total) || 0;
                    return va - vb;
                case 'status':
                    va = da.reviewDecision || da.status;
                    vb = db.reviewDecision || db.status;
                    break;
                default:
                    return a - b;
            }
            return String(va).localeCompare(String(vb));
        });

        return indices;
    }

    function renderSidebar() {
        const listEl = document.getElementById('aiscan-sidebar-list');
        const countersEl = document.getElementById('aiscan-status-counters');
        if (!listEl) {
            return;
        }

        const sorted = getSortedIndices();
        const total = state.documents.length;
        const ready = state.documents.filter(d => d.status === STATUS.READY).length;
        const analyzed = state.documents.filter(d => d.status === STATUS.ANALYZED).length;
        const analyzing = state.documents.filter(d => d.status === STATUS.ANALYZING).length;
        const discarded = state.documents.filter(d => d.status === STATUS.DISCARDED).length;
        const needsReview = state.documents.filter(d => d.status === STATUS.NEEDS_REVIEW).length;
        const failed = state.documents.filter(d => d.status === STATUS.FAILED).length;
        const pending = state.documents.filter(d => d.status === STATUS.PENDING).length;

        if (countersEl) {
            countersEl.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                const tip = bootstrap.Tooltip.getInstance(el);
                if (tip) {
                    tip.dispose();
                }
            });

            const legend = [
                {cls: 'text-bg-secondary', icon: 'fa-clock', count: pending, label: trans('aiscan-status-pending')},
                {cls: 'text-bg-info', icon: 'fa-spinner', count: analyzing, label: trans('aiscan-status-analyzing')},
                {cls: 'text-bg-primary', icon: 'fa-check', count: analyzed, label: trans('aiscan-status-analyzed')},
                {cls: 'text-bg-warning', icon: 'fa-exclamation-triangle', count: needsReview, label: trans('aiscan-status-needs_review')},
                {cls: 'text-bg-success', icon: 'fa-check-circle', count: ready, label: trans('aiscan-status-ready')},
                {cls: 'text-bg-dark', icon: 'fa-ban', count: discarded, label: trans('aiscan-status-discarded')},
                {cls: 'text-bg-danger', icon: 'fa-times-circle', count: failed, label: trans('aiscan-status-failed')},
            ];
            countersEl.innerHTML = legend.map(s =>
                `<span class="badge ${s.cls}${s.count === 0 ? ' opacity-25' : ''}" data-bs-toggle="tooltip" data-bs-placement="bottom" title="${escapeAttr(s.label)}"><i class="fa-solid ${s.icon} me-1"></i>${s.count}</span>`
            ).join('') + `<span class="text-muted small fw-semibold">${ready + discarded} / ${total}</span>`;

            countersEl.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                new bootstrap.Tooltip(el, {trigger: 'hover'});
            });
        }

        listEl.innerHTML = sorted.map(i => {
            const doc = state.documents[i];
            const invoice = doc.extractedData?.invoice || {};
            const supplier = doc.extractedData?.supplier || {};
            const info = STATUS_LABELS[doc.status] || STATUS_LABELS.pending;
            const isActive = i === state.currentIndex;
            const isSelected = state.selectedIndices.has(i);
            const invoiceNum = invoice.number || doc.originalName;
            const supplierName = supplier.name || '-';
            const dateStr = invoice.issue_date || '-';
            const totalStr = invoice.total != null ? String(invoice.total) : '-';

            return `<div class="list-group-item${isActive ? ' active' : ''}" data-doc-index="${i}">
                <div class="d-flex align-items-start">
                    <input type="checkbox" class="form-check-input me-2 mt-1 aiscan-row-check"
                        data-index="${i}" ${isSelected ? 'checked' : ''}>
                    <div class="flex-grow-1" style="min-width:0">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-truncate fw-semibold">${escapeHtml(invoiceNum)}</span>
                            <span class="badge ${info.cls} ms-1" style="white-space:nowrap">
                                <i class="fa-solid ${info.icon}"></i>
                            </span>
                        </div>
                        <div class="text-muted text-truncate">${escapeHtml(supplierName)}</div>
                        <div class="d-flex justify-content-between small text-muted">
                            <span>${escapeHtml(dateStr)}</span>
                            <span>${escapeHtml(totalStr)}</span>
                        </div>
                    </div>
                </div>
            </div>`;
        }).join('');

        // Bind clicks
        listEl.querySelectorAll('.list-group-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (e.target.classList.contains('aiscan-row-check')) {
                    return;
                }
                const idx = parseInt(item.dataset.docIndex);
                navigateTo(idx);
            });
        });

        listEl.querySelectorAll('.aiscan-row-check').forEach(cb => {
            cb.addEventListener('change', () => {
                const idx = parseInt(cb.dataset.index);
                if (cb.checked) {
                    state.selectedIndices.add(idx);
                } else {
                    state.selectedIndices.delete(idx);
                }
                updateSelectAll();
            });
        });

        updateImportButton();
    }

    function updateSelectAll() {
        const selectAll = document.getElementById('aiscan-select-all');
        if (selectAll) {
            selectAll.checked = state.selectedIndices.size > 0
                && state.selectedIndices.size === state.documents.length;
            selectAll.indeterminate = state.selectedIndices.size > 0
                && state.selectedIndices.size < state.documents.length;
        }
    }


    // ── Bulk Actions ───────────────────────────────────────────────────

    function applyBulkAction() {
        const actionSelect = document.getElementById('aiscan-bulk-action');
        const action = actionSelect?.value;
        if (!action || state.selectedIndices.size === 0) {
            return;
        }

        persistCurrentFormState();

        const toReanalyze = [];

        state.selectedIndices.forEach(i => {
            const doc = state.documents[i];
            if (!doc) {
                return;
            }

            switch (action) {
                case 'approve':
                    if (doc.status === STATUS.ANALYZED || doc.status === STATUS.NEEDS_REVIEW) {
                        doc.reviewDecision = 'approved';
                        doc.status = STATUS.READY;
                    }
                    break;
                case 'discard':
                    if (doc.status !== STATUS.PENDING && doc.status !== STATUS.ANALYZING) {
                        doc.reviewDecision = 'discarded';
                        doc.status = STATUS.DISCARDED;
                    }
                    break;
                case 'reanalyze':
                    if (doc.tmpFile && doc.status !== STATUS.ANALYZING) {
                        toReanalyze.push(doc);
                    }
                    break;
            }
        });

        state.selectedIndices.clear();
        if (actionSelect) {
            actionSelect.value = '';
        }
        updateSelectAll();
        renderSidebar();
        renderCurrentDocument();

        if (toReanalyze.length > 0) {
            reanalyzeDocs(toReanalyze);
        }
    }

    // ── Import Button Gate ─────────────────────────────────────────────

    function updateImportButton() {
        const btn = document.getElementById('aiscan-proceed-import-btn');
        if (!btn) {
            return;
        }

        const nonFailed = state.documents.filter(d => d.status !== STATUS.FAILED);
        const allDecided = nonFailed.length > 0
            && nonFailed.every(d => d.reviewDecision !== null);

        btn.disabled = !allDecided;

        if (allDecided) {
            btn.title = '';
        } else {
            const undecided = nonFailed.filter(d => d.reviewDecision === null).length;
            btn.title = trans('aiscan-undecided-remaining', {'%count%': String(undecided)});
        }
    }

    // ── Navigation & Rendering ─────────────────────────────────────────

    function navigateTo(index) {
        if (index < 0 || index >= state.documents.length) {
            return;
        }
        persistCurrentFormState();
        state.currentIndex = index;
        renderCurrentDocument();
    }

    function renderCurrentDocument() {
        const doc = currentDoc();
        if (!doc) {
            return;
        }

        renderPreview(doc);
        renderReviewPanel(doc);
        renderSidebar();
    }

    function renderPreview(doc) {
        const area = document.getElementById('aiscan-preview-area');
        const title = document.getElementById('aiscan-preview-title');
        if (!area) {
            return;
        }

        title.textContent = doc.originalName;
        area.innerHTML = '';

        if (doc.mimeType === 'application/pdf' && doc.objectUrl) {
            area.innerHTML = `<iframe src="${doc.objectUrl}#navpanes=0&toolbar=1&scrollbar=1" title="${escapeAttr(doc.originalName)}"></iframe>`;
        } else if (doc.objectUrl) {
            area.style.position = 'relative';
            area.innerHTML = `
                <div class="aiscan-img-container" style="height:100%;overflow:auto;cursor:grab">
                    <img src="${doc.objectUrl}" alt="${escapeAttr(doc.originalName)}" style="transform-origin:top left;transition:transform 0.2s" class="aiscan-zoom-img">
                </div>
                <div class="position-absolute bottom-0 end-0 m-2 d-flex gap-1" style="z-index:2">
                    <button class="btn btn-sm btn-light shadow-sm aiscan-zoom-out" title="Zoom -"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                    <button class="btn btn-sm btn-light shadow-sm aiscan-zoom-reset" title="Reset"><i class="fa-solid fa-expand"></i></button>
                    <button class="btn btn-sm btn-light shadow-sm aiscan-zoom-in" title="Zoom +"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
                </div>`;
            let zoomLevel = 1;
            const img = area.querySelector('.aiscan-zoom-img');
            const container = area.querySelector('.aiscan-img-container');
            area.querySelector('.aiscan-zoom-in')?.addEventListener('click', () => {
                zoomLevel = Math.min(zoomLevel + 0.25, 4);
                img.style.transform = `scale(${zoomLevel})`;
            });
            area.querySelector('.aiscan-zoom-out')?.addEventListener('click', () => {
                zoomLevel = Math.max(zoomLevel - 0.25, 0.5);
                img.style.transform = `scale(${zoomLevel})`;
            });
            area.querySelector('.aiscan-zoom-reset')?.addEventListener('click', () => {
                zoomLevel = 1;
                img.style.transform = 'scale(1)';
            });
            // Drag to pan
            let isDragging = false;
            let startX = 0;
            let startY = 0;
            let scrollLeft = 0;
            let scrollTop = 0;
            container.addEventListener('mousedown', e => {
                isDragging = true;
                container.style.cursor = 'grabbing';
                startX = e.pageX - container.offsetLeft;
                startY = e.pageY - container.offsetTop;
                scrollLeft = container.scrollLeft;
                scrollTop = container.scrollTop;
                e.preventDefault();
            });
            container.addEventListener('mousemove', e => {
                if (!isDragging) {
                    return;
                }
                const x = e.pageX - container.offsetLeft;
                const y = e.pageY - container.offsetTop;
                container.scrollLeft = scrollLeft - (x - startX);
                container.scrollTop = scrollTop - (y - startY);
            });
            container.addEventListener('mouseup', () => {
                isDragging = false;
                container.style.cursor = 'grab';
            });
            container.addEventListener('mouseleave', () => {
                isDragging = false;
                container.style.cursor = 'grab';
            });
        } else {
            area.innerHTML = `<p class="text-muted text-center p-4">${escapeHtml(trans('aiscan-no-preview'))}</p>`;
        }

        const titleEl = document.getElementById('aiscan-preview-title');
        if (titleEl) {
            titleEl.textContent = doc.originalName;
            titleEl.title = doc.originalName;
        }

        const statusEl = document.getElementById('aiscan-status');
        const jsonBtn = doc.extractedData
            ? ` <button class="btn btn-outline-secondary btn-sm py-0 px-1 ms-1 aiscan-debug-json-btn" type="button" title="JSON"><i class="fa-solid fa-code"></i></button>`
            : '';
        if (doc.error) {
            statusEl.innerHTML = `<div class="alert alert-danger py-1 px-2 small mb-0">${escapeHtml(doc.error)}${jsonBtn}</div>`;
        } else if (doc.status === STATUS.ANALYZING) {
            statusEl.innerHTML = `<div class="alert alert-info py-1 px-2 small mb-0"><i class="fa-solid fa-spinner fa-spin me-1"></i>${escapeHtml(trans('aiscan-analysis-started', {'%provider%': state.defaultProvider}))}</div>`;
        } else if (doc.status === STATUS.ANALYZED || doc.status === STATUS.READY || doc.status === STATUS.NEEDS_REVIEW) {
            statusEl.innerHTML = `<div class="alert alert-success py-1 px-2 small mb-0 d-flex align-items-center justify-content-between">${escapeHtml(trans('aiscan-analysis-completed', {'%provider%': doc.extractedData?._provider || state.defaultProvider}))}${jsonBtn}</div>`;
        } else if (doc.status === STATUS.DISCARDED) {
            statusEl.innerHTML = `<div class="alert alert-dark py-1 px-2 small mb-0"><i class="fa-solid fa-ban me-1"></i>${escapeHtml(trans('aiscan-doc-discarded'))}</div>`;
        } else {
            statusEl.innerHTML = '';
        }

        // Bind JSON debug button
        const debugBtn = statusEl.querySelector('.aiscan-debug-json-btn');
        if (debugBtn) {
            debugBtn.addEventListener('click', () => {
                showJsonDebugModal(doc.extractedData);
            });
        }

        const stateBadge = document.getElementById('aiscan-doc-state-badge');
        if (stateBadge) {
            const info = STATUS_LABELS[doc.status] || STATUS_LABELS.pending;
            stateBadge.className = `badge ${info.cls}`;
            stateBadge.innerHTML = `<i class="fa-solid ${info.icon} me-1"></i>${escapeHtml(trans('aiscan-status-' + doc.status))}`;
        }
    }

    function renderReviewPanel(doc) {
        const review = document.getElementById('aiscan-review');
        if (!review) {
            return;
        }

        if (doc.status === STATUS.DISCARDED) {
            review.innerHTML = `<div class="text-center text-muted p-4"><i class="fa-solid fa-ban fa-2x mb-2"></i><p>${escapeHtml(trans('aiscan-doc-discarded'))}</p></div>`;
            return;
        }

        if (!doc.extractedData) {
            if (doc.status === STATUS.ANALYZING) {
                review.innerHTML = `<div class="text-center p-4"><i class="fa-solid fa-spinner fa-spin fa-2x text-primary mb-2"></i><p class="text-muted">${escapeHtml(trans('aiscan-analyzing'))}</p></div>`;
            } else if (doc.status === STATUS.PENDING) {
                review.innerHTML = `<div class="text-center p-4"><i class="fa-solid fa-clock fa-2x text-secondary mb-2"></i><p class="text-muted">${escapeHtml(trans('aiscan-queued-for-analysis'))}</p></div>`;
            } else if (doc.status === STATUS.FAILED) {
                review.innerHTML = `<div class="text-center p-4"><i class="fa-solid fa-times-circle fa-2x text-danger mb-2"></i><p class="text-danger">${escapeHtml(doc.error || trans('aiscan-status-failed'))}</p></div>`;
            } else {
                review.innerHTML = `<p class="text-muted mb-0">${escapeHtml(trans('aiscan-initial-review-message'))}</p>`;
            }
            return;
        }

        const data = doc.extractedData;
        const invoice = data.invoice || {};
        const supplier = data.supplier || {};
        const lines = Array.isArray(data.lines) ? data.lines : [];
        const validationErrors = Array.isArray(data._validation_errors) ? data._validation_errors : [];
        const confidence = data.confidence || {};

        review.innerHTML = '';

        if (validationErrors.length > 0) {
            review.insertAdjacentHTML('beforeend', `
                <div class="alert alert-warning py-2">
                    <strong class="small">${escapeHtml(trans('aiscan-validation-warnings'))}</strong>
                    <ul class="mb-0 small">${validationErrors.map(e => `<li>${escapeHtml(e)}</li>`).join('')}</ul>
                </div>
            `);
        }

        const supplierMatched = supplier.match_status === 'matched' || supplier.match_status === 'selected' || supplier.match_status === 'created';
        const badgeLabels = {
            matched: trans('aiscan-detected'),
            selected: trans('aiscan-selected'),
            created: trans('aiscan-supplier-created'),
        };
        const badgeLabel = badgeLabels[supplier.match_status] || badgeLabels.matched;
        const supplierSummary = supplierMatched
            ? ` <span class="text-muted fw-normal ms-2">&middot; ${escapeHtml(supplier.matched_name || supplier.name || '')} (${escapeHtml(supplier.tax_id || '')})</span> <span class="badge text-bg-success ms-1">${escapeHtml(badgeLabel)}</span>`
            : (supplier.match_status === 'not_found' ? ` <span class="badge text-bg-warning ms-2">${escapeHtml(trans('aiscan-no-supplier-matched'))}</span>` : '');

        const supplierBody = supplierMatched
            ? `<div class="d-flex gap-2 mb-1">
                    <div style="flex:2">${buildInput(trans('name'), 'supplier_name', supplier.name || '', 'text', null, confidence.supplier_name)}</div>
                    <div style="flex:1">${buildInput(trans('tax-id'), 'supplier_tax_id', supplier.tax_id || '', 'text', null, confidence.supplier_tax_id)}</div>
                </div>
                <div class="d-flex gap-2">
                    <div style="flex:1">${buildInput(trans('email'), 'supplier_email', supplier.email || '')}</div>
                    <div style="flex:1">${buildInput(trans('phone'), 'supplier_phone', supplier.phone || '')}</div>
                </div>
                <input type="hidden" id="supplier_address" value="${escapeAttr(supplier.address || '')}">
                <select id="supplier_match_select" class="d-none"><option value="${escapeAttr(supplier.matched_supplier_id || '')}" selected></option></select>
                ${buildSupplierStatus(supplier)}`
            : `<div class="d-flex gap-2 mb-1">
                    <div style="flex:2">${buildInput(trans('name'), 'supplier_name', supplier.name || '', 'text', null, confidence.supplier_name)}</div>
                    <div style="flex:1">${buildInput(trans('tax-id'), 'supplier_tax_id', supplier.tax_id || '', 'text', null, confidence.supplier_tax_id)}</div>
                </div>
                <div class="d-flex gap-2">
                    <div style="flex:1">${buildInput(trans('email'), 'supplier_email', supplier.email || '')}</div>
                    <div style="flex:1">${buildInput(trans('phone'), 'supplier_phone', supplier.phone || '')}</div>
                </div>
                ${buildTextarea(trans('address'), 'supplier_address', supplier.address || '')}
                ${buildSupplierStatus(supplier)}`;

        review.appendChild(buildSection(trans('aiscan-section-supplier'), supplierBody,
            {collapsed: supplierMatched, headerExtra: supplierSummary}));

        review.appendChild(buildSection(trans('aiscan-section-invoice'), `
            <div class="d-flex gap-2 mb-1">
                <div style="flex:2">${buildInput(trans('number'), 'invoice_number', invoice.number || '', 'text', null, confidence.invoice_number)}</div>
                <div style="flex:1">${buildInput(trans('date'), 'invoice_issue_date', invoice.issue_date || '', 'date', null, confidence.issue_date)}</div>
                <div style="flex:1">${buildInput(trans('expiration'), 'invoice_due_date', invoice.due_date || '', 'date')}</div>
            </div>
            <div class="mb-1">
                <input class="form-control form-control-sm text-truncate" id="invoice_summary" type="text" value="${escapeAttr(invoice.summary || '')}" placeholder="${escapeAttr(trans('summary'))}">
            </div>
            ${invoice.payment_terms ? `<input type="hidden" id="invoice_payment_terms" value="${escapeAttr(invoice.payment_terms)}">` : ''}
        `));

        if (state.importMode === 'total') {
            review.appendChild(buildDefaultProductSection(supplier));
        }

        if (state.importMode === 'lines') {
            review.appendChild(buildLinesSection(lines));
        }

        // Totals section — always calculated from lines, readonly
        review.appendChild(buildSection(trans('aiscan-section-totals'), `
            <input type="hidden" id="invoice_currency" value="${escapeAttr(invoice.currency || 'EUR')}">
            <div class="d-flex gap-2">
                <div style="flex:1">
                    <div class="mb-2">
                        <label class="form-label small mb-1">${escapeHtml(trans('subtotal'))}</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fa-solid fa-euro-sign fa-fw"></i></span>
                            <input class="form-control form-control-sm" id="invoice_subtotal" type="text" readonly value="0,00">
                        </div>
                    </div>
                </div>
                <div style="flex:1">
                    <div class="mb-2">
                        <label class="form-label small mb-1">${escapeHtml(trans('tax-amount'))}</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fa-solid fa-euro-sign fa-fw"></i></span>
                            <input class="form-control form-control-sm" id="invoice_tax_amount" type="text" readonly value="0,00">
                        </div>
                    </div>
                </div>
                <div style="flex:1">
                    <div class="mb-2">
                        <label class="form-label small mb-1">${escapeHtml(trans('irpf'))}</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fa-solid fa-euro-sign fa-fw"></i></span>
                            <input class="form-control form-control-sm" id="invoice_withholding" type="text" readonly value="0,00">
                        </div>
                    </div>
                </div>
                <div style="flex:1">
                    <div class="mb-2">
                        <label class="form-label small mb-1 fw-bold">${escapeHtml(trans('total'))}</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fa-solid fa-euro-sign fa-fw"></i></span>
                            <input class="form-control form-control-sm fw-bold" id="invoice_total" type="text" readonly value="0,00">
                        </div>
                    </div>
                </div>
            </div>
        `));

        review.insertAdjacentHTML('beforeend', `
            <div class="d-flex justify-content-end gap-2 mt-3 mb-2">
                <button type="button" class="btn btn-success" id="aiscan-mark-ready-btn">
                    <i class="fa-solid fa-check me-1"></i>${escapeHtml(trans('aiscan-mark-ready'))}
                </button>
            </div>
        `);

        document.getElementById('aiscan-mark-ready-btn')?.addEventListener('click', markCurrentReady);
        bindSupplierSearch();
    }

    let collapseCounter = 0;

    function buildSection(title, bodyHtml, {collapsed = false, headerExtra = ''} = {}) {
        const id = 'aiscan-collapse-' + (++collapseCounter);
        const section = document.createElement('div');
        section.className = 'card mb-2';
        section.innerHTML = `
            <div class="card-header py-1 px-2" role="button" data-bs-toggle="collapse" data-bs-target="#${id}" aria-expanded="${!collapsed}" aria-controls="${id}" style="cursor:pointer">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="small fw-semibold"><i class="fa-solid fa-chevron-${collapsed ? 'right' : 'down'} me-1 aiscan-collapse-icon" style="font-size:0.65rem;transition:transform 0.2s"></i>${escapeHtml(title)}${headerExtra}</span>
                </div>
            </div>
            <div class="collapse ${collapsed ? '' : 'show'}" id="${id}">
                <div class="card-body py-2 px-2">${bodyHtml}</div>
            </div>
        `;
        // Defer event binding until after DOM insertion
        setTimeout(() => {
            const icon = section.querySelector('.aiscan-collapse-icon');
            const collapseEl = section.querySelector('.collapse');
            if (icon && collapseEl) {
                collapseEl.addEventListener('show.bs.collapse', () => {
                    icon.classList.replace('fa-chevron-right', 'fa-chevron-down');
                });
                collapseEl.addEventListener('hide.bs.collapse', () => {
                    icon.classList.replace('fa-chevron-down', 'fa-chevron-right');
                });
            }
        }, 0);
        return section;
    }

    function buildInput(label, id, value, type, step, confidence) {
        const badge = confidence != null
            ? ` <span class="badge ${confidence >= 0.7 ? 'text-bg-success' : confidence >= 0.4 ? 'text-bg-warning' : 'text-bg-danger'}" title="${escapeAttr(trans('confidence'))}">${Math.round(confidence * 100)}%</span>`
            : '';
        return `
            <div class="mb-2">
                <label class="form-label small mb-1" for="${id}">${escapeHtml(label)}${badge}</label>
                <input class="form-control form-control-sm" id="${id}" type="${type || 'text'}" ${step ? `step="${step}"` : ''} value="${escapeAttr(value)}">
            </div>
        `;
    }

    function buildTextarea(label, id, value) {
        return `
            <div class="mb-2">
                <label class="form-label small mb-1" for="${id}">${escapeHtml(label)}</label>
                <textarea class="form-control form-control-sm" id="${id}" rows="2">${escapeHtml(value)}</textarea>
            </div>
        `;
    }

    function buildSupplierStatus(supplier) {
        const status = supplier.match_status || 'not_found';
        const variants = {
            ambiguous: {klass: 'warning', text: trans('aiscan-supplier-ambiguous')},
            created: {klass: 'info', text: trans('aiscan-supplier-created-on-save')},
            matched: {klass: 'success', text: trans('aiscan-matched-with', {'%name%': supplier.matched_name || ''})},
            not_found: {klass: 'secondary', text: trans('aiscan-supplier-not-found-on-save')},
        };
        const variant = variants[status] || variants.not_found;

        let select = '';
        if (status === 'ambiguous' && Array.isArray(supplier.candidates) && supplier.candidates.length > 0) {
            select = `
                <div class="mt-2">
                    <select id="supplier_match_select" class="form-select form-select-sm">
                        ${supplier.candidates.map(c => `<option value="${escapeAttr(c.id)}">${escapeHtml(c.name)} (${escapeHtml(c.tax_id || '')})</option>`).join('')}
                    </select>
                </div>
            `;
        }

        const searchBox = `
            <div class="mt-2">
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" id="aiscan-supplier-search" placeholder="${escapeAttr(trans('search'))}">
                    <button class="btn btn-outline-secondary" type="button" id="aiscan-supplier-search-btn"><i class="fa-solid fa-search"></i></button>
                    <button class="btn btn-outline-primary" type="button" id="aiscan-create-supplier-btn"><i class="fa-solid fa-plus me-1"></i>${escapeHtml(trans('aiscan-create-supplier'))}</button>
                </div>
                <div id="aiscan-supplier-results" class="list-group mt-1" style="max-height:150px;overflow-y:auto"></div>
                <span class="small" id="aiscan-create-supplier-status"></span>
            </div>
        `;

        return `<div class="alert alert-${variant.klass} small mb-0">${escapeHtml(variant.text)}${select}</div>${searchBox}`;
    }

    function buildDefaultProductSection(supplier) {
        const codproveedor = supplier.matched_supplier_id || '';
        const section = document.createElement('div');
        section.className = 'card mb-3';
        section.innerHTML = `
            <div class="card-header py-2">${escapeHtml(trans('aiscan-default-product'))}</div>
            <div class="card-body py-2">
                <div class="mb-2">
                    <label class="form-label small mb-1">${escapeHtml(trans('aiscan-default-product-help'))}</label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="aiscan-product-search" placeholder="${escapeAttr(trans('search'))}">
                        <button class="btn btn-outline-secondary" type="button" id="aiscan-product-search-btn"><i class="fa-solid fa-search"></i></button>
                    </div>
                    <div id="aiscan-product-results" class="list-group mt-1" style="max-height:150px;overflow-y:auto"></div>
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1" for="default_product_ref">${escapeHtml(trans('reference'))}</label>
                    <input class="form-control form-control-sm" id="default_product_ref" type="text" value="" data-codproveedor="${escapeAttr(codproveedor)}">
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm" id="aiscan-save-default-product">
                    <i class="fa-solid fa-floppy-disk me-1"></i>${escapeHtml(trans('aiscan-save-default-product'))}
                </button>
                <span class="small text-muted ms-2" id="aiscan-default-product-status"></span>
            </div>
        `;

        setTimeout(() => {
            if (codproveedor) {
                loadDefaultProduct(codproveedor);
            }
            bindProductSearch();
            document.getElementById('aiscan-save-default-product')?.addEventListener('click', saveDefaultProduct);
        }, 0);

        return section;
    }

    async function loadDefaultProduct(codproveedor) {
        try {
            const params = new URLSearchParams({action: 'get-supplier-default-product', codproveedor});
            const response = await fetch('AiScanInvoice?' + params.toString());
            const data = await response.json();
            if (data.found) {
                const refInput = document.getElementById('default_product_ref');
                if (refInput) {
                    refInput.value = data.referencia;
                }
                const statusEl = document.getElementById('aiscan-default-product-status');
                if (statusEl) {
                    statusEl.textContent = data.description || data.referencia;
                }
            }
        } catch (e) {
            // silent
        }
    }

    function bindProductSearch() {
        const searchInput = document.getElementById('aiscan-product-search');
        const searchBtn = document.getElementById('aiscan-product-search-btn');
        const resultsDiv = document.getElementById('aiscan-product-results');
        if (!searchInput || !searchBtn || !resultsDiv) {
            return;
        }

        let timer = null;
        const doSearch = () => {
            const query = searchInput.value.trim();
            if (query.length < 2) {
                resultsDiv.innerHTML = '';
                return;
            }
            fetch('AiScanInvoice?' + new URLSearchParams({action: 'search-products', query}))
                .then(r => r.json())
                .then(data => {
                    const items = data.results || [];
                    if (items.length === 0) {
                        resultsDiv.innerHTML = `<div class="list-group-item small text-muted">${escapeHtml(trans('aiscan-no-results'))}</div>`;
                        return;
                    }
                    resultsDiv.innerHTML = items.map(p =>
                        `<button type="button" class="list-group-item list-group-item-action small py-1" data-ref="${escapeAttr(p.referencia)}" data-desc="${escapeAttr(p.description)}">
                            <strong>${escapeHtml(p.referencia)}</strong> <span class="text-muted">${escapeHtml(p.description)}</span>
                        </button>`
                    ).join('');
                })
                .catch(() => { resultsDiv.innerHTML = ''; });
        };

        searchInput.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(doSearch, 300); });
        searchBtn.addEventListener('click', doSearch);
        searchInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });

        resultsDiv.addEventListener('click', e => {
            const item = e.target.closest('[data-ref]');
            if (!item) {
                return;
            }
            const refInput = document.getElementById('default_product_ref');
            if (refInput) {
                refInput.value = item.dataset.ref;
            }
            resultsDiv.innerHTML = '';
            searchInput.value = '';
        });
    }

    async function saveDefaultProduct() {
        const refInput = document.getElementById('default_product_ref');
        const codproveedor = refInput?.dataset.codproveedor;
        const referencia = refInput?.value?.trim();

        if (!codproveedor || !referencia) {
            return;
        }

        try {
            const response = await fetch('AiScanInvoice?action=set-supplier-default-product', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({codproveedor, referencia}),
            });
            const data = await response.json();
            const statusEl = document.getElementById('aiscan-default-product-status');
            if (statusEl) {
                statusEl.textContent = data.success ? trans('aiscan-saved') : (data.error || 'Error');
                statusEl.className = data.success ? 'small text-success ms-2' : 'small text-danger ms-2';
            }
        } catch (e) {
            // silent
        }
    }

    function buildTaxSelect(selectedRate, selectedCode) {
        const taxes = window.aiscanTaxTypes || [];
        const rate = parseFloat(selectedRate) || 0;
        const code = selectedCode || '';
        let options = '<option value="">------</option>';
        let found = false;
        for (const t of taxes) {
            const sel = (code && t.code === code) || (!code && !found && Math.abs(t.rate - rate) < 0.01);
            if (sel) {
                found = true;
            }
            options += `<option value="${escapeAttr(t.code)}"${sel ? ' selected' : ''}>${escapeHtml(t.description)}</option>`;
        }
        return `<select class="form-select form-select-sm" data-field="tax_code" style="width:120px">${options}</select>`;
    }

    function buildWithholdingSelect(selectedRate, selectedCode) {
        const types = window.aiscanWithholdingTypes || [];
        const rate = parseFloat(selectedRate) || 0;
        const code = selectedCode || '';
        let options = '<option value="">------</option>';
        for (const t of types) {
            if (!t.code) {
                continue;
            }
            const sel = (code && t.code === code) || (!code && rate > 0 && Math.abs(t.rate - rate) < 0.01);
            options += `<option value="${escapeAttr(t.rate)}"${sel ? ' selected' : ''}>${escapeHtml(t.description)}</option>`;
        }
        return `<select class="form-select form-select-sm" data-field="irpf" style="width:120px">${options}</select>`;
    }

    function initTaxSelects(container) {
        container.querySelectorAll('.aiscan-line-row').forEach(row => {
            const rate = parseFloat(row.dataset.taxRate || 0);
            const taxCode = row.dataset.taxCode || '';
            const irpfCode = row.dataset.irpfCode || '';

            // Sync hidden iva field
            const hiddenTax = row.querySelector('[data-field="iva"]');
            if (hiddenTax) {
                hiddenTax.value = rate;
            }

            // Select tax in modal — prefer code match, fallback to rate match
            const modalTax = row.querySelector('.aiscan-modal-tax');
            if (modalTax) {
                let found = false;
                if (taxCode) {
                    const taxes = window.aiscanTaxTypes || [];
                    const match = taxes.find(t => t.code === taxCode);
                    if (match) {
                        for (const opt of modalTax.options) {
                            if (parseFloat(opt.value) === match.rate) {
                                opt.selected = true;
                                found = true;
                                break;
                            }
                        }
                    }
                }
                if (!found) {
                    for (const opt of modalTax.options) {
                        if (Math.abs(parseFloat(opt.value) - rate) < 0.01) {
                            opt.selected = true;
                            break;
                        }
                    }
                }
            }

            // Select IRPF in modal — prefer code match
            const modalIrpf = row.querySelector('.aiscan-modal-irpf');
            if (modalIrpf && irpfCode) {
                const types = window.aiscanWithholdingTypes || [];
                const match = types.find(t => t.code === irpfCode);
                if (match) {
                    for (const opt of modalIrpf.options) {
                        if (parseFloat(opt.value) === match.rate) {
                            opt.selected = true;
                            break;
                        }
                    }
                }
            }
        });
    }

    function fmtNumber(n) {
        return n.toLocaleString(document.documentElement.lang || 'es', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function calcLineTotal(row) {
        const qty = parseFloat(row.querySelector('[data-field="cantidad"]')?.value || 0);
        const price = parseFloat(row.querySelector('[data-field="pvpunitario"]')?.value || 0);
        const discount = parseFloat(row.querySelector('[data-field="dtopor"]')?.value || 0);
        const tax = parseFloat(row.querySelector('[data-field="iva"]')?.value || 0);
        const irpf = parseFloat(row.querySelector('[data-field="irpf"]')?.value || 0);
        const base = qty * price * (1 - discount / 100);
        const taxAmount = base * (tax / 100);
        const irpfAmount = base * (irpf / 100);
        const total = base + taxAmount - irpfAmount;

        const totalInput = row.querySelector('.aiscan-line-total');
        if (totalInput) {
            totalInput.value = fmtNumber(total);
        }
        const modalTotal = row.querySelector('.aiscan-modal-total');
        if (modalTotal) {
            modalTotal.textContent = fmtNumber(total);
        }
        // Update modal summary if present
        const modalBase = row.querySelector('.aiscan-modal-base');
        if (modalBase) {
            modalBase.textContent = fmtNumber(base);
        }
        const modalTax = row.querySelector('.aiscan-modal-tax-amount');
        if (modalTax) {
            modalTax.textContent = fmtNumber(taxAmount);
        }
        const modalIrpf = row.querySelector('.aiscan-modal-irpf-amount');
        if (modalIrpf) {
            modalIrpf.textContent = fmtNumber(irpfAmount);
        }
    }

    function calcAllLineTotals() {
        let totalBase = 0;
        let totalTax = 0;
        let totalIrpf = 0;
        let totalFinal = 0;
        document.querySelectorAll('#aiscan-lines-body .aiscan-line-row').forEach(row => {
            calcLineTotal(row);
            const qty = parseFloat(row.querySelector('[data-field="cantidad"]')?.value || 0);
            const price = parseFloat(row.querySelector('[data-field="pvpunitario"]')?.value || 0);
            const discount = parseFloat(row.querySelector('[data-field="dtopor"]')?.value || 0);
            const tax = parseFloat(row.querySelector('[data-field="iva"]')?.value || 0);
            const irpf = parseFloat(row.querySelector('[data-field="irpf"]')?.value || 0);
            const base = qty * price * (1 - discount / 100);
            totalBase += base;
            totalTax += base * (tax / 100);
            totalIrpf += base * (irpf / 100);
            totalFinal += base + base * (tax / 100) - base * (irpf / 100);
        });
        const subtotalEl = document.getElementById('invoice_subtotal');
        if (subtotalEl) { subtotalEl.value = fmtNumber(totalBase); }
        const taxEl = document.getElementById('invoice_tax_amount');
        if (taxEl) { taxEl.value = fmtNumber(totalTax); }
        const irpfEl = document.getElementById('invoice_withholding');
        if (irpfEl) { irpfEl.value = fmtNumber(totalIrpf); }
        const totalEl = document.getElementById('invoice_total');
        if (totalEl) { totalEl.value = fmtNumber(totalFinal); }
    }

    function taxCodeToRate(code) {
        const taxes = window.aiscanTaxTypes || [];
        const match = taxes.find(t => t.code === code);
        return match ? match.rate : 0;
    }

    function calcModalPreview(modal) {
        const qty = parseFloat(modal.querySelector('.aiscan-modal-quantity')?.value || 0);
        const price = parseFloat(modal.querySelector('.aiscan-modal-price')?.value || 0);
        const discount = parseFloat(modal.querySelector('.aiscan-modal-discount')?.value || 0);
        const taxCode = modal.querySelector('.aiscan-modal-tax')?.value || '';
        const tax = taxCodeToRate(taxCode);
        const irpf = parseFloat(modal.querySelector('.aiscan-modal-irpf')?.value || 0);
        const base = qty * price * (1 - discount / 100);
        const taxAmt = base * (tax / 100);
        const irpfAmt = base * (irpf / 100);
        const total = base + taxAmt - irpfAmt;
        const b = modal.querySelector('.aiscan-modal-base');
        if (b) { b.textContent = fmtNumber(base); }
        const t = modal.querySelector('.aiscan-modal-tax-amount');
        if (t) { t.textContent = fmtNumber(taxAmt); }
        const i = modal.querySelector('.aiscan-modal-irpf-amount');
        if (i) { i.textContent = fmtNumber(irpfAmt); }
        const tot = modal.querySelector('.aiscan-modal-total');
        if (tot) { tot.textContent = fmtNumber(total); }
    }

    function applyModalToRow(modal, row) {
        const fields = {
            descripcion: '.aiscan-modal-description',
            cantidad: '.aiscan-modal-quantity',
            pvpunitario: '.aiscan-modal-price',
            dtopor: '.aiscan-modal-discount',
        };
        for (const [field, sel] of Object.entries(fields)) {
            const el = modal.querySelector(sel);
            if (el) {
                row.querySelector(`[data-field="${field}"]`).value = el.value;
            }
        }
        const taxModal = modal.querySelector('.aiscan-modal-tax');
        if (taxModal) {
            const selectedCode = taxModal.value;
            row.querySelector('[data-field="codimpuesto"]').value = selectedCode;
            const taxes = window.aiscanTaxTypes || [];
            const match = taxes.find(t => t.code === selectedCode);
            row.querySelector('[data-field="iva"]').value = match ? match.rate : 0;
        }
        const irpfModal = modal.querySelector('.aiscan-modal-irpf');
        if (irpfModal) {
            row.querySelector('[data-field="irpf"]').value = irpfModal.value;
        }
        const excModal = modal.querySelector('.aiscan-modal-excepcioniva');
        if (excModal) {
            row.querySelector('[data-field="excepcioniva"]').value = excModal.value;
        }
        const supModal = modal.querySelector('.aiscan-modal-suplido');
        if (supModal) {
            row.querySelector('[data-field="suplido"]').value = supModal.value;
        }
        calcAllLineTotals();
    }

    function showJsonDebugModal(data) {
        let modal = document.getElementById('aiscan-json-debug-modal');
        if (!modal) {
            document.body.insertAdjacentHTML('beforeend', `
                <div class="modal fade" id="aiscan-json-debug-modal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header py-2">
                                <h6 class="modal-title"><i class="fa-solid fa-code fa-fw me-1"></i>JSON</h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-0">
                                <pre class="m-0 p-3 small" id="aiscan-json-debug-content" style="max-height:70vh;overflow:auto;white-space:pre-wrap;word-break:break-all"></pre>
                            </div>
                            <div class="modal-footer py-1">
                                <button type="button" class="btn btn-sm btn-outline-primary" id="aiscan-json-copy-btn"><i class="fa-solid fa-copy me-1"></i>${escapeHtml(trans('aiscan-copy-json'))}</button>
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">${escapeHtml(trans('close'))}</button>
                            </div>
                        </div>
                    </div>
                </div>
            `);
            modal = document.getElementById('aiscan-json-debug-modal');
            document.getElementById('aiscan-json-copy-btn').addEventListener('click', () => {
                const text = document.getElementById('aiscan-json-debug-content').textContent;
                navigator.clipboard.writeText(text).catch(() => {});
            });
        }
        document.getElementById('aiscan-json-debug-content').textContent = JSON.stringify(data, null, 2);
        new bootstrap.Modal(modal).show();
    }

    let productSearchTargetRow = null;

    function ensureProductSearchModal() {
        if (document.getElementById('aiscan-find-product-modal')) {
            return;
        }
        document.body.insertAdjacentHTML('beforeend', `
            <div class="modal fade" id="aiscan-find-product-modal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header py-2">
                            <h6 class="modal-title"><i class="fa-solid fa-book fa-fw me-1"></i>${escapeHtml(trans('aiscan-search-product'))}</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control" id="aiscan-product-modal-input" placeholder="${escapeAttr(trans('search'))}...">
                                <button class="btn btn-outline-secondary" type="button" id="aiscan-product-modal-search-btn"><i class="fa-solid fa-search"></i></button>
                            </div>
                            <div id="aiscan-product-modal-results" class="list-group" style="max-height:250px;overflow-y:auto"></div>
                        </div>
                    </div>
                </div>
            </div>
        `);

        const input = document.getElementById('aiscan-product-modal-input');
        const searchBtn = document.getElementById('aiscan-product-modal-search-btn');
        const resultsDiv = document.getElementById('aiscan-product-modal-results');
        let timer = null;

        const doSearch = () => {
            const query = input.value.trim();
            if (query.length < 2) {
                resultsDiv.innerHTML = '';
                return;
            }
            fetch('AiScanInvoice?' + new URLSearchParams({action: 'search-products', query}))
                .then(r => r.json())
                .then(data => {
                    const items = data.results || [];
                    if (items.length === 0) {
                        resultsDiv.innerHTML = `<div class="list-group-item small text-muted">${escapeHtml(trans('aiscan-no-results'))}</div>`;
                    } else {
                        resultsDiv.innerHTML = items.map(p =>
                            `<button type="button" class="list-group-item list-group-item-action small py-1 aiscan-product-modal-pick" data-ref="${escapeAttr(p.referencia)}" data-desc="${escapeAttr(p.description)}">
                                <strong>${escapeHtml(p.referencia)}</strong> <span class="text-muted">${escapeHtml(p.description)}</span>
                            </button>`
                        ).join('');
                    }
                })
                .catch(() => { resultsDiv.innerHTML = ''; });
        };

        input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(doSearch, 300); });
        searchBtn.addEventListener('click', doSearch);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });

        resultsDiv.addEventListener('click', e => {
            const pick = e.target.closest('.aiscan-product-modal-pick');
            if (!pick || !productSearchTargetRow) {
                return;
            }
            const refInput = productSearchTargetRow.querySelector('[data-field="referencia"]');
            if (refInput) {
                refInput.value = pick.dataset.ref;
            }
            const badge = productSearchTargetRow.querySelector('.aiscan-ref-badge');
            if (badge) {
                badge.innerHTML = `<span class="badge text-bg-success" title="${escapeAttr(pick.dataset.ref)}"><i class="fa-solid fa-link"></i></span>`;
            }
            bootstrap.Modal.getInstance(document.getElementById('aiscan-find-product-modal'))?.hide();
            productSearchTargetRow = null;
        });
    }

    function openProductSearchModal(row) {
        ensureProductSearchModal();
        productSearchTargetRow = row;
        const input = document.getElementById('aiscan-product-modal-input');
        const resultsDiv = document.getElementById('aiscan-product-modal-results');
        const desc = row.querySelector('[data-field="description"]')?.value || '';
        input.value = desc;
        resultsDiv.innerHTML = '';
        const modal = new bootstrap.Modal(document.getElementById('aiscan-find-product-modal'));
        modal.show();
        setTimeout(() => { input.select(); }, 300);
        // Auto-search with current description
        if (desc.length >= 2) {
            input.dispatchEvent(new Event('input'));
        }
    }

    function buildLineRow(line, index) {
        const ref = line.referencia || line.sku || '';
        const matchBadge = ref
            ? `<span class="badge text-bg-success" title="${escapeAttr(ref)}"><i class="fa-solid fa-link"></i></span>`
            : `<span class="badge text-bg-secondary"><i class="fa-solid fa-unlink"></i></span>`;
        const modalId = 'aiscan-line-modal-' + index;
        const desc = line.descripcion || line.description || '';
        const qty = line.cantidad ?? line.quantity ?? 1;
        const price = line.pvpunitario ?? line.unit_price ?? 0;
        const dto = line.dtopor ?? line.discount ?? 0;
        const dto2 = line.dtopor2 ?? 0;
        const taxRate = line.iva ?? line.tax_rate ?? 0;
        const taxCode = line.codimpuesto || line.tax_code || '';
        const irpfRate = line.irpf ?? 0;
        const irpfCode = line.codretencion || line.irpf_code || '';
        const recargo = line.recargo ?? 0;
        const excepcion = line.excepcioniva ?? '';
        const suplido = line.suplido ? '1' : '0';
        return `<div class="aiscan-line-row d-flex gap-1 align-items-center py-1 border-bottom" data-line-index="${index}" data-tax-rate="${taxRate}" data-tax-code="${escapeAttr(taxCode)}" data-irpf-code="${escapeAttr(irpfCode)}">
            <div style="flex:4;position:relative">
                <div class="input-group input-group-sm">
                    <input class="form-control form-control-sm" data-field="descripcion" value="${escapeAttr(desc)}">
                    <input type="hidden" data-field="referencia" value="${escapeAttr(ref)}">
                    <button class="btn btn-info btn-sm aiscan-product-match-btn" type="button" title="${escapeAttr(trans('aiscan-search-product'))}"><i class="fa-solid fa-book fa-fw"></i></button>
                    <span class="input-group-text p-0 px-1 aiscan-ref-badge">${matchBadge}</span>
                </div>
            </div>
            <input class="form-control form-control-sm aiscan-calc" data-field="cantidad" type="number" step="any" value="${escapeAttr(qty)}" style="width:55px">
            <div class="input-group input-group-sm" style="width:95px">
                <span class="input-group-text p-1"><i class="fa-solid fa-euro-sign fa-fw" style="font-size:0.65rem"></i></span>
                <input class="form-control form-control-sm aiscan-calc" data-field="pvpunitario" type="number" step="any" value="${escapeAttr(price)}">
            </div>
            <div class="input-group input-group-sm" style="width:85px">
                <span class="input-group-text p-1"><i class="fa-solid fa-euro-sign fa-fw" style="font-size:0.65rem"></i></span>
                <input class="form-control form-control-sm fw-bold aiscan-line-total" type="text" readonly value="0,00">
            </div>
            <button type="button" class="btn btn-sm btn-light aiscan-line-detail-btn" data-bs-toggle="modal" data-bs-target="#${modalId}" title="${escapeAttr(trans('aiscan-more-options'))}"><i class="fa-solid fa-ellipsis"></i></button>
            <button type="button" class="btn btn-sm btn-outline-danger aiscan-delete-line" title="${escapeAttr(trans('aiscan-delete-line'))}"><i class="fa-solid fa-trash-can"></i></button>
            <input type="hidden" data-field="dtopor" class="aiscan-calc" value="${escapeAttr(dto)}">
            <input type="hidden" data-field="dtopor2" value="${escapeAttr(dto2)}">
            <input type="hidden" data-field="codimpuesto" value="${escapeAttr(taxCode)}">
            <input type="hidden" data-field="iva" class="aiscan-calc" value="${escapeAttr(taxRate)}">
            <input type="hidden" data-field="recargo" value="${escapeAttr(recargo)}">
            <input type="hidden" data-field="irpf" class="aiscan-calc" value="${escapeAttr(irpfRate)}">
            <input type="hidden" data-field="excepcioniva" value="${escapeAttr(excepcion)}">
            <input type="hidden" data-field="suplido" value="${escapeAttr(suplido)}">
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header py-2">
                            <h6 class="modal-title"><i class="fa-solid fa-pen-to-square fa-fw me-1"></i>${escapeHtml(trans('aiscan-more-options'))}</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label small mb-1">${escapeHtml(trans('description'))}</label>
                                    <input type="text" class="form-control form-control-sm aiscan-modal-description" value="${escapeAttr(desc)}">
                                </div>
                                <div class="col-4">
                                    <label class="form-label small mb-1">${escapeHtml(trans('quantity'))}</label>
                                    <input type="number" class="form-control form-control-sm aiscan-modal-quantity" step="any" value="${escapeAttr(qty)}">
                                </div>
                                <div class="col-4">
                                    <label class="form-label small mb-1">${escapeHtml(trans('price'))}</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="fa-solid fa-euro-sign fa-fw"></i></span>
                                        <input type="number" class="form-control aiscan-modal-price" step="any" value="${escapeAttr(price)}">
                                    </div>
                                </div>
                                <div class="col-4">
                                    <label class="form-label small mb-1">% ${escapeHtml(trans('discount'))}</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control aiscan-modal-discount" min="0" max="100" step="any" value="${escapeAttr(dto)}">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">${escapeHtml(trans('tax'))}</label>
                                    ${buildTaxSelect(taxRate, taxCode).replace('data-field="tax_code"', 'class="form-select form-select-sm aiscan-modal-tax"')}
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">IRPF</label>
                                    ${buildWithholdingSelect(irpfRate, irpfCode).replace('data-field="irpf"', 'class="form-select form-select-sm aiscan-modal-irpf"')}
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">${escapeHtml(trans('aiscan-tax-exception'))}</label>
                                    <select class="form-select form-select-sm aiscan-modal-excepcioniva">
                                        <option value=""${excepcion === '' ? ' selected' : ''}>------</option>
                                        <option value="ES_20"${excepcion === 'ES_20' ? ' selected' : ''}>Art. 20 LIVA</option>
                                        <option value="ES_21"${excepcion === 'ES_21' ? ' selected' : ''}>Art. 21 LIVA</option>
                                        <option value="ES_22"${excepcion === 'ES_22' ? ' selected' : ''}>Art. 22 LIVA</option>
                                        <option value="ES_23_24"${excepcion === 'ES_23_24' ? ' selected' : ''}>Art. 23-24 LIVA</option>
                                        <option value="ES_25"${excepcion === 'ES_25' ? ' selected' : ''}>Art. 25 LIVA</option>
                                        <option value="ES_OTHER"${excepcion === 'ES_OTHER' ? ' selected' : ''}>Otras exenciones</option>
                                        <option value="ES_PASSIVE_SUBJECT"${excepcion === 'ES_PASSIVE_SUBJECT' ? ' selected' : ''}>N6 Inversión sujeto pasivo</option>
                                        <option value="ES_ART_7"${excepcion === 'ES_ART_7' ? ' selected' : ''}>N3 No sujeta art. 7</option>
                                        <option value="ES_ART_14"${excepcion === 'ES_ART_14' ? ' selected' : ''}>N4 No sujeta art. 14</option>
                                        <option value="ES_LOCATION_RULES"${excepcion === 'ES_LOCATION_RULES' ? ' selected' : ''}>N2 Reglas localización</option>
                                        <option value="ES_N1"${excepcion === 'ES_N1' ? ' selected' : ''}>IGIC art. 10.1</option>
                                        <option value="ES_N5"${excepcion === 'ES_N5' ? ' selected' : ''}>IGIC art. 25</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">${escapeHtml(trans('aiscan-suplido'))}</label>
                                    <select class="form-select form-select-sm aiscan-modal-suplido">
                                        <option value="0"${suplido === '0' ? ' selected' : ''}>No</option>
                                        <option value="1"${suplido === '1' ? ' selected' : ''}>Si</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <hr class="my-1">
                                    <div class="d-flex justify-content-between small">
                                        <span>${escapeHtml(trans('subtotal'))}: <strong class="aiscan-modal-base">0,00</strong></span>
                                        <span>${escapeHtml(trans('tax'))}: <strong class="aiscan-modal-tax-amount">0,00</strong></span>
                                        <span>IRPF: <strong class="aiscan-modal-irpf-amount">0,00</strong></span>
                                        <span class="fw-bold">${escapeHtml(trans('total'))}: <strong class="aiscan-modal-total">0,00</strong></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer py-1">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">${escapeHtml(trans('cancel'))}</button>
                            <button type="button" class="btn btn-sm btn-primary aiscan-modal-accept" data-bs-dismiss="modal">${escapeHtml(trans('accept'))}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
    }

    function buildLinesSection(lines) {
        const displayLines = lines.length > 0 ? lines : [{descripcion: '', cantidad: 1, pvpunitario: 0, dtopor: 0, iva: 0, irpf: 0}];

        const rows = displayLines.map((line, index) => buildLineRow(line, index)).join('');

        const section = buildSection(trans('aiscan-section-products'), `
            <div class="d-flex gap-1 align-items-center py-1 border-bottom mb-1 small text-muted">
                <div style="flex:4">${escapeHtml(trans('description'))}</div>
                <div style="width:55px">${escapeHtml(trans('quantity'))}</div>
                <div style="width:95px">${escapeHtml(trans('price'))}</div>
                <div style="width:85px">${escapeHtml(trans('total'))}</div>
                <div style="width:56px"></div>
            </div>
            <div id="aiscan-lines-body">${rows}</div>
            <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="aiscan-add-line-btn">
                <i class="fa-solid fa-plus me-1"></i>${escapeHtml(trans('aiscan-add-line'))}
            </button>
        `);

        setTimeout(() => {
            initTaxSelects(section);
            calcAllLineTotals();
        }, 0);

        // Recalculate line totals on any value change
        section.addEventListener('input', e => {
            if (e.target.closest('.aiscan-calc')) {
                calcAllLineTotals();
            }
        });
        // Modal: recalculate preview on input (without syncing to row)
        section.addEventListener('input', e => {
            const modal = e.target.closest('.modal');
            if (modal) {
                calcModalPreview(modal);
                return;
            }
        });
        section.addEventListener('change', e => {
            const modal = e.target.closest('.modal');
            if (modal) {
                calcModalPreview(modal);
                return;
            }
            if (e.target.matches('[data-field="iva"]') || e.target.matches('[data-field="irpf"]')) {
                calcAllLineTotals();
            }
        });

        section.addEventListener('click', e => {
            const deleteBtn = e.target.closest('.aiscan-delete-line');
            if (deleteBtn) {
                const row = deleteBtn.closest('.aiscan-line-row');
                if (row && document.querySelectorAll('#aiscan-lines-body .aiscan-line-row').length > 1) {
                    row.remove();
                }
            }
            const addBtn = e.target.closest('#aiscan-add-line-btn');
            if (addBtn) {
                const container = document.getElementById('aiscan-lines-body');
                const newIndex = container.querySelectorAll('.aiscan-line-row').length;
                container.insertAdjacentHTML('beforeend', buildLineRow(
                    {descripcion: '', cantidad: 1, pvpunitario: 0, dtopor: 0, iva: 0, irpf: 0}, newIndex
                ));
            }

            // Modal accept — sync values to row
            const acceptBtn = e.target.closest('.aiscan-modal-accept');
            if (acceptBtn) {
                const modal = acceptBtn.closest('.modal');
                const row = modal?.closest('.aiscan-line-row');
                if (modal && row) {
                    applyModalToRow(modal, row);
                }
            }

            // Product search button — open shared modal
            const matchBtn = e.target.closest('.aiscan-product-match-btn');
            if (matchBtn) {
                const row = matchBtn.closest('.aiscan-line-row');
                openProductSearchModal(row);
            }
        });

        return section;
    }

    function bindSupplierSearch() {
        const searchInput = document.getElementById('aiscan-supplier-search');
        const searchBtn = document.getElementById('aiscan-supplier-search-btn');
        const resultsDiv = document.getElementById('aiscan-supplier-results');
        if (!searchInput || !searchBtn || !resultsDiv) {
            return;
        }

        let timer = null;
        const doSearch = () => {
            const query = searchInput.value.trim();
            if (query.length < 2) {
                resultsDiv.innerHTML = '';
                return;
            }
            fetch('AiScanInvoice?' + new URLSearchParams({action: 'search-suppliers', query}))
                .then(r => r.json())
                .then(data => {
                    const items = data.results || [];
                    if (items.length === 0) {
                        resultsDiv.innerHTML = `<div class="list-group-item small text-muted">${escapeHtml(trans('aiscan-no-results'))}</div>`;
                        return;
                    }
                    resultsDiv.innerHTML = items.map(s =>
                        `<button type="button" class="list-group-item list-group-item-action small py-1" data-id="${escapeAttr(s.id)}" data-name="${escapeAttr(s.name)}" data-taxid="${escapeAttr(s.tax_id || '')}">
                            <strong>${escapeHtml(s.name)}</strong> <span class="text-muted">${escapeHtml(s.tax_id || '')}</span>
                        </button>`
                    ).join('');
                })
                .catch(() => { resultsDiv.innerHTML = ''; });
        };

        searchInput.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(doSearch, 300); });
        searchBtn.addEventListener('click', doSearch);
        searchInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });

        resultsDiv.addEventListener('click', e => {
            const item = e.target.closest('[data-id]');
            if (!item) {
                return;
            }
            const nameInput = document.getElementById('supplier_name');
            const taxIdInput = document.getElementById('supplier_tax_id');
            if (nameInput) {
                nameInput.value = item.dataset.name;
            }
            if (taxIdInput) {
                taxIdInput.value = item.dataset.taxid;
            }

            // Update document supplier data and re-render collapsed
            const doc = currentDoc();
            if (doc?.extractedData?.supplier) {
                doc.extractedData.supplier.matched_supplier_id = item.dataset.id;
                doc.extractedData.supplier.matched_name = item.dataset.name;
                doc.extractedData.supplier.match_status = 'selected';
                doc.extractedData.supplier.name = item.dataset.name;
                doc.extractedData.supplier.tax_id = item.dataset.taxid;
            }
            renderReviewPanel(doc);
        });

        // Bind ambiguous supplier dropdown selection
        const matchSelect = document.getElementById('supplier_match_select');
        if (matchSelect && matchSelect.options.length > 1) {
            matchSelect.addEventListener('change', () => {
                const opt = matchSelect.selectedOptions[0];
                if (!opt) {
                    return;
                }
                const doc = currentDoc();
                if (doc?.extractedData?.supplier) {
                    const text = opt.textContent.trim();
                    const nameMatch = text.match(/^(.+?)\s*\(([^)]*)\)$/);
                    doc.extractedData.supplier.matched_supplier_id = opt.value;
                    doc.extractedData.supplier.matched_name = nameMatch ? nameMatch[1] : text;
                    doc.extractedData.supplier.match_status = 'selected';
                    doc.extractedData.supplier.tax_id = nameMatch ? nameMatch[2] : '';
                    doc.extractedData.supplier.name = doc.extractedData.supplier.matched_name;
                }
                renderReviewPanel(doc);
            });
        }

        // Bind inline create supplier button
        const createBtn = document.getElementById('aiscan-create-supplier-btn');
        if (createBtn) {
            createBtn.addEventListener('click', createSupplierInline);
        }
    }

    async function createSupplierInline() {
        const nameEl = document.getElementById('supplier_name');
        const taxIdEl = document.getElementById('supplier_tax_id');
        const emailEl = document.getElementById('supplier_email');
        const phoneEl = document.getElementById('supplier_phone');
        const statusEl = document.getElementById('aiscan-create-supplier-status');
        const btn = document.getElementById('aiscan-create-supplier-btn');

        const name = nameEl?.value?.trim();
        if (!name) {
            if (statusEl) {
                statusEl.className = 'small text-danger';
                statusEl.textContent = trans('aiscan-supplier-name-required');
            }
            return;
        }

        btn.disabled = true;
        if (statusEl) {
            statusEl.className = 'small text-muted';
            statusEl.textContent = '...';
        }

        try {
            const response = await fetch('AiScanInvoice?action=create-supplier', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    name,
                    tax_id: taxIdEl?.value?.trim() || '',
                    email: emailEl?.value?.trim() || '',
                    phone: phoneEl?.value?.trim() || '',
                }),
            });
            const data = await response.json();

            if (data.error) {
                throw new Error(data.error);
            }

            // Update current document's supplier data
            const doc = currentDoc();
            if (doc?.extractedData?.supplier) {
                doc.extractedData.supplier.matched_supplier_id = data.supplier.id;
                doc.extractedData.supplier.matched_name = data.supplier.name;
                doc.extractedData.supplier.match_status = 'created';
                doc.extractedData.supplier.name = data.supplier.name;
                doc.extractedData.supplier.tax_id = data.supplier.tax_id;
            }

            // Re-render to show collapsed matched state
            renderReviewPanel(doc);

            if (statusEl) {
                statusEl.className = 'small text-success';
                statusEl.textContent = trans('aiscan-supplier-created-success');
            }
        } catch (error) {
            if (statusEl) {
                statusEl.className = 'small text-danger';
                statusEl.textContent = error.message || trans('aiscan-supplier-create-error');
            }
        } finally {
            if (btn) {
                btn.disabled = false;
            }
        }
    }

    function persistCurrentFormState() {
        const doc = currentDoc();
        if (!doc || !doc.extractedData) {
            return;
        }
        doc.extractedData = collectFormData(doc.extractedData);
    }

    function collectFormData(baseData) {
        const data = JSON.parse(JSON.stringify(baseData));
        data.invoice = data.invoice || {};
        data.supplier = data.supplier || {};

        data.invoice.number = val('invoice_number');
        data.invoice.issue_date = val('invoice_issue_date');
        data.invoice.due_date = val('invoice_due_date');
        data.invoice.currency = val('invoice_currency');
        data.invoice.subtotal = parseFloat(val('invoice_subtotal') || 0);
        data.invoice.tax_amount = parseFloat(val('invoice_tax_amount') || 0);
        data.invoice.total = parseFloat(val('invoice_total') || 0);
        const withholdingEl = document.getElementById('invoice_withholding');
        if (withholdingEl) {
            data.invoice.withholding_amount = parseFloat(withholdingEl.value || 0);
        }
        data.invoice.summary = val('invoice_summary');
        const ptEl = document.getElementById('invoice_payment_terms');
        if (ptEl) {
            data.invoice.payment_terms = ptEl.value;
        }

        data.supplier.name = val('supplier_name');
        data.supplier.tax_id = val('supplier_tax_id');
        data.supplier.email = val('supplier_email');
        data.supplier.phone = val('supplier_phone');
        data.supplier.address = val('supplier_address');

        const selectedSupplier = document.getElementById('supplier_match_select');
        if (selectedSupplier) {
            data.supplier.matched_supplier_id = selectedSupplier.value;
            delete data.supplier.create_if_missing;
        }

        if (state.importMode === 'lines') {
            data.lines = Array.from(document.querySelectorAll('#aiscan-lines-body .aiscan-line-row')).map(row => {
                const line = {};
                row.querySelectorAll('[data-field]').forEach(input => {
                    line[input.dataset.field] = input.type === 'number' ? parseFloat(input.value || 0) : input.value;
                });
                return line;
            });
        }

        return data;
    }

    function val(id) {
        return document.getElementById(id)?.value || '';
    }

    function markCurrentReady() {
        persistCurrentFormState();
        const doc = currentDoc();
        if (!doc || !doc.extractedData) {
            return;
        }

        if (!doc.extractedData.supplier?.matched_supplier_id) {
            const selectedSupplier = document.getElementById('supplier_match_select');
            if (!selectedSupplier) {
                if (!window.confirm(trans('aiscan-create-new-supplier-confirm'))) {
                    return;
                }
                doc.extractedData.supplier.create_if_missing = true;
            }
        }

        doc.status = STATUS.READY;
        doc.reviewDecision = 'approved';
        renderCurrentDocument();

        // Auto-advance to next unreviewed document
        const nextUnreviewed = state.documents.findIndex(
            (d, i) => i !== state.currentIndex && d.reviewDecision === null
                && d.status !== STATUS.FAILED && d.status !== STATUS.PENDING
                && d.status !== STATUS.ANALYZING
        );
        if (nextUnreviewed >= 0) {
            navigateTo(nextUnreviewed);
        }
    }

    function discardCurrentDoc() {
        const doc = currentDoc();
        if (!doc) {
            return;
        }
        doc.status = STATUS.DISCARDED;
        doc.reviewDecision = 'discarded';
        renderCurrentDocument();

        // Auto-advance to next unreviewed document
        const nextUnreviewed = state.documents.findIndex(
            (d, i) => i !== state.currentIndex && d.reviewDecision === null
                && d.status !== STATUS.FAILED && d.status !== STATUS.PENDING
                && d.status !== STATUS.ANALYZING
        );
        if (nextUnreviewed >= 0) {
            navigateTo(nextUnreviewed);
        }
    }

    async function reanalyzeDocs(docs) {
        const provider = document.getElementById('aiscan-provider-select')?.value || state.defaultProvider;

        for (const doc of docs) {
            doc.status = STATUS.ANALYZING;
            doc.error = null;
            doc.reviewDecision = null;
            renderSidebar();
            if (doc.index === state.currentIndex) {
                renderPreview(doc);
                renderReviewPanel(doc);
            }

            try {
                const params = new URLSearchParams({
                    action: 'analyze',
                    tmp_file: doc.tmpFile,
                    mime_type: doc.mimeType,
                    import_mode: state.importMode,
                    use_history: state.useHistory ? '1' : '0',
                    supplier_id: doc.extractedData?.supplier?.matched_supplier_id || '',
                    provider: provider,
                });

                const response = await fetch('AiScanInvoice?' + params.toString());
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                doc.extractedData = data.data;
                doc.status = STATUS.ANALYZED;
            } catch (error) {
                doc.status = STATUS.NEEDS_REVIEW;
                doc.error = error.message;
            }

            renderSidebar();
            if (doc.index === state.currentIndex) {
                renderPreview(doc);
                renderReviewPanel(doc);
            }
        }
    }

    async function reanalyzeCurrentDoc() {
        const doc = currentDoc();
        if (!doc || !doc.tmpFile) {
            return;
        }

        const provider = document.getElementById('aiscan-provider-select')?.value || state.defaultProvider;
        doc.status = STATUS.ANALYZING;
        doc.error = null;
        doc.reviewDecision = null;
        renderCurrentDocument();

        try {
            const params = new URLSearchParams({
                action: 'analyze',
                tmp_file: doc.tmpFile,
                mime_type: doc.mimeType,
                import_mode: state.importMode,
                use_history: state.useHistory ? '1' : '0',
                supplier_id: doc.extractedData?.supplier?.matched_supplier_id || '',
                provider: provider,
            });

            const response = await fetch('AiScanInvoice?' + params.toString());
            const data = await response.json();

            if (data.error) {
                throw new Error(data.error);
            }

            doc.extractedData = data.data;
            doc.status = STATUS.ANALYZED;
        } catch (error) {
            doc.status = STATUS.NEEDS_REVIEW;
            doc.error = error.message;
        }

        renderCurrentDocument();
    }

    // ── Step 3: Import ─────────────────────────────────────────────────

    function bindImportStep() {
        document.getElementById('aiscan-back-to-review')?.addEventListener('click', () => {
            showStep('review');
            renderCurrentDocument();
        });
        document.getElementById('aiscan-import-all-btn')?.addEventListener('click', executeImport);
    }

    function buildImportSummary() {
        const tbody = document.getElementById('aiscan-import-body');
        if (!tbody) {
            return;
        }

        tbody.innerHTML = state.documents.map((doc, i) => {
            const invoice = doc.extractedData?.invoice || {};
            const supplier = doc.extractedData?.supplier || {};
            const info = STATUS_LABELS[doc.status] || STATUS_LABELS.pending;
            return `
                <tr>
                    <td>${i + 1}</td>
                    <td>${escapeHtml(doc.originalName)}</td>
                    <td>${escapeHtml(supplier.name || '-')}</td>
                    <td>${escapeHtml(invoice.number || '-')}</td>
                    <td>${escapeHtml(invoice.issue_date || '-')}</td>
                    <td>${escapeHtml(invoice.total ?? '-')}</td>
                    <td><span class="badge ${info.cls}"><i class="fa-solid ${info.icon} me-1"></i>${escapeHtml(trans('aiscan-status-' + doc.status))}</span></td>
                    <td>${doc.status !== STATUS.DISCARDED && doc.status !== STATUS.FAILED && doc.status !== STATUS.IMPORTED ? `<button class="btn btn-sm btn-outline-danger aiscan-toggle-discard" data-index="${i}"><i class="fa-solid fa-ban"></i></button>` : ''}${doc.status === STATUS.IMPORTED && doc.invoiceId ? `<a href="EditFacturaProveedor?code=${encodeURIComponent(doc.invoiceId)}" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-eye"></i></a>` : ''}</td>
                </tr>
            `;
        }).join('');

        tbody.querySelectorAll('.aiscan-toggle-discard').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.dataset.index);
                const doc = state.documents[idx];
                if (doc) {
                    doc.status = doc.status === STATUS.DISCARDED ? STATUS.READY : STATUS.DISCARDED;
                    buildImportSummary();
                }
            });
        });

        const countEl = document.getElementById('aiscan-import-count');
        if (countEl) {
            const importable = state.documents.filter(d => d.status === STATUS.READY || d.status === STATUS.ANALYZED).length;
            countEl.textContent = trans('aiscan-import-count', {'%count%': String(importable), '%total%': String(state.documents.length)});
        }
    }

    async function executeImport() {
        const importBtn = document.getElementById('aiscan-import-all-btn');
        importBtn.disabled = true;
        importBtn.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-1"></i>${escapeHtml(trans('aiscan-importing'))}`;

        const documents = state.documents.map(doc => ({
            status: doc.status,
            extracted_data: doc.extractedData,
            tmp_file: doc.tmpFile,
            mime_type: doc.mimeType,
            original_name: doc.originalName,
        }));

        try {
            const response = await fetch('AiScanInvoice?action=import-batch', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({documents, import_mode: state.importMode, provider: state.defaultProvider}),
            });
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Import failed');
            }

            (data.results || []).forEach(result => {
                const doc = state.documents[result.index];
                if (!doc) {
                    return;
                }
                if (result.status === 'imported') {
                    doc.status = STATUS.IMPORTED;
                    doc.invoiceId = result.invoice_id;
                } else if (result.status === 'error') {
                    doc.status = STATUS.FAILED;
                    doc.error = result.error;
                } else if (result.status === 'skipped') {
                    doc.status = STATUS.DISCARDED;
                }
            });

            buildImportSummary();
            showImportResults(data.results || [], data.batch_id || null);
        } catch (error) {
            alert(error.message);
        } finally {
            importBtn.disabled = false;
            importBtn.innerHTML = `<i class="fa-solid fa-file-import me-1"></i>${escapeHtml(trans('aiscan-import-all'))}`;
        }
    }

    function showImportResults(results, batchId) {
        const imported = results.filter(r => r.status === 'imported');
        const importBtn = document.getElementById('aiscan-import-all-btn');
        const backBtn = document.getElementById('aiscan-back-to-review');
        const countEl = document.getElementById('aiscan-import-count');
        const footer = importBtn?.closest('.card-footer');

        if (imported.length > 0) {
            importBtn.classList.add('d-none');
            if (backBtn) {
                backBtn.classList.add('d-none');
            }
            if (countEl) {
                countEl.classList.add('d-none');
            }

            if (footer) {
                const wrapper = document.createElement('div');
                wrapper.className = 'd-flex align-items-center justify-content-end gap-2 w-100';

                const summary = document.createElement('span');
                summary.className = 'text-success fw-semibold';
                summary.innerHTML = `<i class="fa-solid fa-check-circle me-1"></i>${escapeHtml(trans('aiscan-import-complete'))} (${imported.length})`;
                wrapper.appendChild(summary);

                const link = document.createElement('a');
                if (imported.length === 1) {
                    link.href = 'EditFacturaProveedor?code=' + encodeURIComponent(imported[0].invoice_id);
                } else {
                    link.href = 'ListFacturaProveedor';
                }
                link.className = 'btn btn-primary';
                link.innerHTML = `<i class="fa-solid fa-eye me-1"></i>${escapeHtml(trans('aiscan-view-invoice'))}`;
                wrapper.appendChild(link);

                if (batchId) {
                    const histLink = document.createElement('a');
                    histLink.href = 'EditAiScanImportBatch?code=' + encodeURIComponent(batchId);
                    histLink.className = 'btn btn-outline-info';
                    histLink.innerHTML = `<i class="fa-solid fa-clock-rotate-left me-1"></i>${escapeHtml(trans('aiscan-import-history'))}`;
                    wrapper.appendChild(histLink);
                }

                footer.innerHTML = '';
                footer.appendChild(wrapper);
            }
        }
    }

    // ── UI Navigation ──────────────────────────────────────────────────

    function showStep(step) {
        document.getElementById('aiscan-step-upload').classList.toggle('d-none', step !== 'upload');
        document.getElementById('aiscan-step-review').classList.toggle('d-none', step !== 'review');
        document.getElementById('aiscan-step-import').classList.toggle('d-none', step !== 'import');
    }

    function resetUploadUI() {
        state.documents.forEach(doc => {
            if (doc.objectUrl) {
                URL.revokeObjectURL(doc.objectUrl);
            }
        });
        state.documents = [];
        state.currentIndex = 0;
        state.selectedIndices.clear();

        const dropZone = document.getElementById('aiscan-drop-zone');
        dropZone.innerHTML = `
            <div>
                <div class="fs-2 mb-2 text-muted"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                <div class="fw-semibold">${escapeHtml(trans('aiscan-document-and-image-drop'))}</div>
                <div class="small text-muted mt-1">${escapeHtml(trans('aiscan-drop-or-click'))}</div>
                <div class="small text-muted mt-1">PDF, JPG, PNG, WebP</div>
            </div>
        `;

        document.getElementById('aiscan-file-input').value = '';
        document.getElementById('aiscan-upload-btn').disabled = true;
        document.getElementById('aiscan-upload-btn').innerHTML = `<i class="fa-solid fa-cloud-arrow-up me-1"></i>${escapeHtml(trans('aiscan-upload-and-analyze'))}`;

        const fileList = document.getElementById('aiscan-file-list');
        if (fileList) {
            fileList.classList.add('d-none');
        }
    }

    function buildProviderSelect() {
        const select = document.getElementById('aiscan-provider-select');
        if (!select) {
            return;
        }
        select.innerHTML = '';
        (state.availableProviders || []).forEach(p => {
            const opt = document.createElement('option');
            opt.value = p;
            opt.textContent = p;
            if (p === state.defaultProvider) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });
    }

    // ── Split handle ───────────────────────────────────────────────────

    function bindSplitHandle() {
        // Preview / form split handle
        const handle = document.getElementById('aiscan-split-handle');
        const split = document.getElementById('aiscan-split');
        const left = document.getElementById('aiscan-split-left');
        if (handle && split && left) {
            bindResizeHandle(handle, split, left, () => {
                const rect = split.getBoundingClientRect();
                return (offset) => {
                    const pct = Math.min(Math.max((offset / rect.width) * 100, 25), 75);
                    left.style.flexBasis = pct + '%';
                };
            });
        }

        // Sidebar resize handle
        const sidebarHandle = document.getElementById('aiscan-sidebar-handle');
        const layout = document.getElementById('aiscan-review-layout');
        const sidebar = document.getElementById('aiscan-sidebar');
        if (sidebarHandle && layout && sidebar) {
            bindResizeHandle(sidebarHandle, layout, sidebar, () => {
                const rect = layout.getBoundingClientRect();
                return (offset) => {
                    const px = Math.min(Math.max(offset, 200), rect.width * 0.5);
                    sidebar.style.flexBasis = px + 'px';
                };
            });
        }
    }

    function bindResizeHandle(handle, container, target, makeResizer) {
        let dragging = false;
        let resizer = null;

        handle.addEventListener('mousedown', e => {
            e.preventDefault();
            dragging = true;
            resizer = makeResizer();
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
            container.classList.add('aiscan-split-dragging');
        });

        document.addEventListener('mousemove', e => {
            if (!dragging) {
                return;
            }
            const rect = container.getBoundingClientRect();
            const offset = e.clientX - rect.left;
            resizer(offset);
        });

        document.addEventListener('mouseup', () => {
            if (dragging) {
                dragging = false;
                resizer = null;
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                container.classList.remove('aiscan-split-dragging');
            }
        });
    }
})();
