    // ── Step 2: Review ─────────────────────────────────────────────────

    function bindReviewStep() {
        document.getElementById('aiscan-reanalyze-btn')?.addEventListener('click', () => reanalyzeCurrentDoc());
        bindReanalyzeMenu();
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
            state.selectionAnchorIndex = null;
            renderSidebar();
        });

        document.getElementById('aiscan-bulk-apply')?.addEventListener('click', applyBulkAction);

        document.getElementById('aiscan-proceed-import-btn')?.addEventListener('click', () => {
            if (!state.importMode) {
                alert(trans('aiscan-import-mode-required'));
                return;
            }
            persistCurrentFormState();

            // Issue #78: revoke READY docs that lost vital fields after approval.
            let revoked = false;
            state.documents.forEach(doc => {
                if (revokeReadyIfBlocked(doc, doc.extractedData)) {
                    revoked = true;
                }
            });
            if (revoked) {
                renderSidebar();
                renderCurrentDocument();
                updateImportButton();
                alert(trans('aiscan-cannot-mark-ready'));
                return;
            }

            openImportStep();
        });

        const manualEntryCheckbox = document.getElementById('aiscan-manual-entry');
        if (manualEntryCheckbox) {
            manualEntryCheckbox.addEventListener('change', () => {
                state.manualEntryOnly = !!manualEntryCheckbox.checked;
                updateUploadButtonLabel();
            });
            state.manualEntryOnly = !!manualEntryCheckbox.checked;
        }
        updateUploadButtonLabel();
    }

    function updateUploadButtonLabel() {
        const uploadBtn = document.getElementById('aiscan-upload-btn');
        if (!uploadBtn || uploadBtn.querySelector('.fa-spinner')) {
            // Keep spinner text while uploading; only update idle labels.
            return;
        }
        const label = state.manualEntryOnly
            ? trans('aiscan-upload-manual-entry')
            : trans('aiscan-upload-and-analyze');
        const icon = state.manualEntryOnly
            ? 'fa-keyboard'
            : 'fa-cloud-arrow-up';
        uploadBtn.innerHTML = `<i class="fa-solid ${icon} me-1"></i>${escapeHtml(label)}`;
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
            const totalStr = invoice.total != null ? fmtNumber(parseFloat(invoice.total) || 0) : '-';

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
            let shiftPressed = false;

            cb.addEventListener('click', (e) => {
                shiftPressed = e.shiftKey;
            });

            cb.addEventListener('change', () => {
                const index = parseInt(cb.dataset.index, 10);
                const usedRangeSelection = shiftPressed
                    && state.selectionAnchorIndex !== null
                    && applySelectionRange(
                        state.selectedIndices,
                        sorted,
                        state.selectionAnchorIndex,
                        index,
                        cb.checked
                    );

                if (!usedRangeSelection) {
                    if (cb.checked) {
                        state.selectedIndices.add(index);
                    } else {
                        state.selectedIndices.delete(index);
                    }
                }

                shiftPressed = false;
                state.selectionAnchorIndex = index;
                updateSelectAll();

                if (usedRangeSelection) {
                    renderSidebar();
                    return;
                }
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
                    if (
                        (doc.status === STATUS.ANALYZED || doc.status === STATUS.NEEDS_REVIEW)
                        && canMarkDocReady(doc.extractedData)
                    ) {
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
                case 'reanalyze-lines':
                case 'reanalyze-total':
                    if (doc.tmpFile && doc.status !== STATUS.ANALYZING) {
                        toReanalyze.push(doc);
                    }
                    break;
            }
        });

        state.selectedIndices.clear();
        state.selectionAnchorIndex = null;
        if (actionSelect) {
            actionSelect.value = '';
        }
        updateSelectAll();
        renderSidebar();
        renderCurrentDocument();

        if (toReanalyze.length > 0) {
            const modeOverride = action === 'reanalyze-total' ? 'total' : 'lines';
            reanalyzeDocs(toReanalyze, modeOverride);
        }
    }

    // ── Import Button Gate ─────────────────────────────────────────────

    function updateImportButton() {
        const btn = document.getElementById('aiscan-proceed-import-btn');
        if (!btn) {
            return;
        }

        const hasReady = state.documents.some(d =>
            d.status === STATUS.READY && d.extractedData && canMarkDocReady(d.extractedData)
        );
        btn.disabled = !hasReady;
        btn.title = hasReady ? '' : trans('aiscan-mark-ready');
    }

