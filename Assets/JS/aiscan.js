/**
 * AiScan - AI-powered invoice scanning frontend
 * This file is part of AiScan plugin for FacturaScripts.
 */

'use strict';

(() => {
    const state = {
        invoiceId: null,
        mimeType: null,
        originalName: null,
        tmpFile: null,
        objectUrl: null,
    };

    const selectors = {
        modalId: 'modalaiscan',
        acceptBtn: 'aiscan-accept-btn',
        fileInput: 'aiscan-file-input',
        filename: 'aiscan-filename',
        linesBody: 'aiscan-lines-body',
        preview: 'aiscan-preview',
        providerInfo: 'aiscan-provider-info',
        review: 'aiscan-review',
        scanBtn: 'aiscan-scan-btn',
        status: 'aiscan-status',
        supportBadge: 'aiscan-browser-support',
        uploadArea: 'aiscan-drop-area',
    };

    document.addEventListener('DOMContentLoaded', () => {
        ensureModal();
        bindModalEvents();
        updateBrowserPromptSupport();
    });

    function ensureModal() {
        if (document.getElementById(selectors.modalId)) {
            return;
        }

        document.body.insertAdjacentHTML('beforeend', `
            <div class="modal fade aiscan-modal" id="${selectors.modalId}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title mb-1">Scan invoice</h5>
                                <div class="small text-muted" id="${selectors.providerInfo}"></div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-lg-7">
                                    <div class="card h-100">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <span>Document preview</span>
                                            <span class="badge text-bg-secondary" id="${selectors.supportBadge}"></span>
                                        </div>
                                        <div class="card-body">
                                            <input id="${selectors.fileInput}" type="file" class="d-none" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp">
                                            <div class="aiscan-drop-area d-flex align-items-center justify-content-center text-center p-4 mb-3" id="${selectors.uploadArea}">
                                                <div>
                                                    <div class="fs-4 mb-2"><i class="fa-solid fa-file-arrow-up"></i></div>
                                                    <div>Drop a PDF or image invoice here</div>
                                                    <div class="small text-muted">or click to select a file</div>
                                                </div>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <strong id="${selectors.filename}">No file selected</strong>
                                                <button type="button" class="btn btn-primary btn-sm" id="${selectors.scanBtn}" disabled>
                                                    <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Analyze
                                                </button>
                                            </div>
                                            <div id="${selectors.status}" class="small mb-3"></div>
                                            <div class="aiscan-preview d-flex align-items-center justify-content-center" id="${selectors.preview}">
                                                <span class="text-muted">Preview will appear here after upload.</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-5">
                                    <div class="card h-100">
                                        <div class="card-header">Review extracted data</div>
                                        <div class="card-body aiscan-sidebar" id="${selectors.review}">
                                            <p class="text-muted mb-0">Upload a document and analyze it to review supplier, invoice and line data.</p>
                                        </div>
                                        <div class="card-footer text-end">
                                            <button type="button" class="btn btn-success" id="${selectors.acceptBtn}" disabled>
                                                <i class="fa-solid fa-check me-1"></i>Create / update invoice
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `);
    }

    function bindModalEvents() {
        const modal = document.getElementById(selectors.modalId);
        const dropArea = document.getElementById(selectors.uploadArea);
        const fileInput = document.getElementById(selectors.fileInput);
        const scanBtn = document.getElementById(selectors.scanBtn);
        const acceptBtn = document.getElementById(selectors.acceptBtn);

        modal.addEventListener('show.bs.modal', () => resetModal());

        dropArea.addEventListener('dragover', (event) => {
            event.preventDefault();
            dropArea.classList.add('aiscan-drag-over');
        });

        dropArea.addEventListener('dragleave', () => {
            dropArea.classList.remove('aiscan-drag-over');
        });

        dropArea.addEventListener('drop', (event) => {
            event.preventDefault();
            dropArea.classList.remove('aiscan-drag-over');
            if (event.dataTransfer.files.length > 0) {
                handleFile(event.dataTransfer.files[0]);
            }
        });

        dropArea.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                handleFile(fileInput.files[0]);
            }
        });

        scanBtn.addEventListener('click', analyzeDocument);
        acceptBtn.addEventListener('click', acceptExtracted);
    }

    function resetModal() {
        if (state.objectUrl) {
            URL.revokeObjectURL(state.objectUrl);
        }

        state.invoiceId = getInvoiceId();
        state.mimeType = null;
        state.originalName = null;
        state.tmpFile = null;
        state.objectUrl = null;

        document.getElementById(selectors.filename).textContent = 'No file selected';
        document.getElementById(selectors.scanBtn).disabled = true;
        document.getElementById(selectors.acceptBtn).disabled = true;
        document.getElementById(selectors.acceptBtn).dataset.extractedData = '';
        document.getElementById(selectors.preview).innerHTML = '<span class="text-muted">Preview will appear here after upload.</span>';
        document.getElementById(selectors.review).innerHTML = '<p class="text-muted mb-0">Upload a document and analyze it to review supplier, invoice and line data.</p>';
        document.getElementById(selectors.providerInfo).textContent = '';
        setStatus('');
    }

    function getInvoiceId() {
        const params = new URLSearchParams(window.location.search);
        return params.get('code') || document.querySelector('input[name="code"]')?.value || '';
    }

    function getRequestToken() {
        return document.querySelector('input[name="multireqtoken"]')?.value || '';
    }

    function handleFile(file) {
        showPreview(file);
        uploadFile(file);
    }

    function showPreview(file) {
        if (state.objectUrl) {
            URL.revokeObjectURL(state.objectUrl);
        }

        state.objectUrl = URL.createObjectURL(file);
        const preview = document.getElementById(selectors.preview);
        preview.innerHTML = '';

        if (file.type === 'application/pdf') {
            preview.innerHTML = `<iframe src="${state.objectUrl}" title="Invoice preview" style="height:420px"></iframe>`;
        } else {
            preview.innerHTML = `<img src="${state.objectUrl}" alt="Invoice preview" style="max-height:420px; object-fit:contain">`;
        }

        document.getElementById(selectors.filename).textContent = file.name;
    }

    function uploadFile(file) {
        const formData = new FormData();
        formData.append('invoice_file', file);
        formData.append('multireqtoken', getRequestToken());
        setStatus('Uploading file…', 'info');

        fetch('AiScanInvoice?action=upload', {
            method: 'POST',
            body: formData,
        })
            .then((response) => response.json())
            .then((data) => {
                if (data.error) {
                    throw new Error(data.error);
                }

                state.tmpFile = data.tmp_file;
                state.mimeType = data.mime_type;
                state.originalName = data.original_name;
                document.getElementById(selectors.providerInfo).textContent = 'Configured provider: ' + (data.provider || 'unknown');
                document.getElementById(selectors.scanBtn).disabled = false;
                setStatus('File uploaded. Ready to analyze.', 'success');

                if (data.auto_scan) {
                    analyzeDocument();
                }
            })
            .catch((error) => setStatus(error.message, 'danger'));
    }

    function analyzeDocument() {
        if (!state.tmpFile) {
            return;
        }

        setStatus('Analyzing invoice with AI…', 'info');

        const params = new URLSearchParams({
            action: 'analyze',
            tmp_file: state.tmpFile,
            mime_type: state.mimeType || '',
            multireqtoken: getRequestToken(),
        });

        fetch('AiScanInvoice?' + params.toString())
            .then((response) => response.json())
            .then((data) => {
                if (data.error) {
                    throw new Error(data.error);
                }

                renderReviewForm(data.data);
                setStatus('Analysis completed. Review the extracted fields before saving.', 'success');
            })
            .catch((error) => setStatus(error.message, 'danger'));
    }

    function renderReviewForm(data) {
        const review = document.getElementById(selectors.review);
        const invoice = data.invoice || {};
        const supplier = data.supplier || {};
        const taxes = Array.isArray(data.taxes) ? data.taxes : [];
        const lines = Array.isArray(data.lines) ? data.lines : [];
        const validationErrors = Array.isArray(data._validation_errors) ? data._validation_errors : [];

        review.innerHTML = '';

        if (validationErrors.length > 0) {
            review.insertAdjacentHTML('beforeend', `
                <div class="alert alert-warning">
                    <strong>Validation warnings</strong>
                    <ul class="mb-0">${validationErrors.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>
                </div>
            `);
        }

        review.appendChild(buildSection('Supplier', `
            ${buildInput('Name', 'supplier_name', supplier.name || '')}
            ${buildInput('Tax ID', 'supplier_tax_id', supplier.tax_id || '')}
            ${buildInput('Email', 'supplier_email', supplier.email || '')}
            ${buildInput('Phone', 'supplier_phone', supplier.phone || '')}
            ${buildTextarea('Address', 'supplier_address', supplier.address || '')}
            ${buildSupplierStatus(supplier)}
        `));

        review.appendChild(buildSection('Invoice', `
            ${buildInput('Number', 'invoice_number', invoice.number || '')}
            ${buildInput('Issue date', 'invoice_issue_date', invoice.issue_date || '', 'date')}
            ${buildInput('Due date', 'invoice_due_date', invoice.due_date || '', 'date')}
            ${buildInput('Currency', 'invoice_currency', invoice.currency || 'EUR')}
            ${buildInput('Subtotal', 'invoice_subtotal', invoice.subtotal ?? 0, 'number', '0.01')}
            ${buildInput('Tax amount', 'invoice_tax_amount', invoice.tax_amount ?? 0, 'number', '0.01')}
            ${buildInput('Total', 'invoice_total', invoice.total ?? 0, 'number', '0.01')}
            ${buildTextarea('Summary', 'invoice_summary', invoice.summary || '')}
            ${buildTextarea('Notes', 'invoice_notes', invoice.notes || '')}
        `));

        if (taxes.length > 0) {
            review.appendChild(buildSection('Taxes', `
                <div class="small">
                    ${taxes.map((tax) => `${escapeHtml(tax.name || 'Tax')}: ${escapeHtml(tax.rate || 0)}% — ${escapeHtml(tax.amount || 0)}`).join('<br>')}
                </div>
            `));
        }

        review.appendChild(buildLinesSection(lines));

        document.getElementById(selectors.acceptBtn).disabled = false;
        document.getElementById(selectors.acceptBtn).dataset.extractedData = JSON.stringify(data);
    }

    function buildSection(title, bodyHtml) {
        const section = document.createElement('div');
        section.className = 'card mb-3';
        section.innerHTML = `
            <div class="card-header">${escapeHtml(title)}</div>
            <div class="card-body">${bodyHtml}</div>
        `;
        return section;
    }

    function buildInput(label, id, value, type = 'text', step = null) {
        return `
            <div class="mb-2">
                <label class="form-label small mb-1" for="${id}">${escapeHtml(label)}</label>
                <input class="form-control form-control-sm" id="${id}" type="${type}" ${step ? `step="${step}"` : ''} value="${escapeAttribute(value)}">
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
            ambiguous: {klass: 'warning', text: 'Multiple supplier matches found. Select the correct supplier.'},
            created: {klass: 'info', text: 'A new supplier will be created when you save the invoice.'},
            matched: {klass: 'success', text: 'Matched with: ' + (supplier.matched_name || '')},
            not_found: {klass: 'secondary', text: 'No supplier match found. You can create one during save.'},
        };
        const variant = variants[status] || variants.not_found;
        let select = '';

        if (status === 'ambiguous' && Array.isArray(supplier.candidates) && supplier.candidates.length > 0) {
            select = `
                <div class="mt-2">
                    <select id="supplier_match_select" class="form-select form-select-sm">
                        ${supplier.candidates.map((candidate) => `<option value="${escapeAttribute(candidate.id)}">${escapeHtml(candidate.name)} (${escapeHtml(candidate.tax_id || '')})</option>`).join('')}
                    </select>
                </div>
            `;
        }

        return `<div class="alert alert-${variant.klass} small mb-0">${escapeHtml(variant.text)}${select}</div>`;
    }

    function buildLinesSection(lines) {
        const rows = (lines.length > 0 ? lines : [{
            description: 'Scanned supplier invoice',
            discount: 0,
            quantity: 1,
            tax_rate: 0,
            unit_price: 0,
        }]).map((line, index) => `
            <tr data-line-index="${index}">
                <td><input class="form-control form-control-sm" data-field="description" value="${escapeAttribute(line.description || '')}"></td>
                <td><input class="form-control form-control-sm" data-field="quantity" type="number" step="0.01" value="${escapeAttribute(line.quantity ?? 1)}"></td>
                <td><input class="form-control form-control-sm" data-field="unit_price" type="number" step="0.01" value="${escapeAttribute(line.unit_price ?? 0)}"></td>
                <td><input class="form-control form-control-sm" data-field="discount" type="number" step="0.01" value="${escapeAttribute(line.discount ?? 0)}"></td>
                <td><input class="form-control form-control-sm" data-field="tax_rate" type="number" step="0.01" value="${escapeAttribute(line.tax_rate ?? 0)}"></td>
                <td><input class="form-control form-control-sm" data-field="sku" value="${escapeAttribute(line.sku || '')}"></td>
            </tr>
        `).join('');

        return buildSection('Line items', `
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Qty</th>
                            <th>Unit price</th>
                            <th>Discount %</th>
                            <th>Tax %</th>
                            <th>SKU / Ref</th>
                        </tr>
                    </thead>
                    <tbody id="${selectors.linesBody}">${rows}</tbody>
                </table>
            </div>
        `);
    }

    function acceptExtracted() {
        const acceptBtn = document.getElementById(selectors.acceptBtn);
        if (!acceptBtn.dataset.extractedData) {
            return;
        }

        const data = JSON.parse(acceptBtn.dataset.extractedData);
        data.invoice = data.invoice || {};
        data.supplier = data.supplier || {};

        data.invoice.number = readValue('invoice_number');
        data.invoice.issue_date = readValue('invoice_issue_date');
        data.invoice.due_date = readValue('invoice_due_date');
        data.invoice.currency = readValue('invoice_currency');
        data.invoice.subtotal = parseFloat(readValue('invoice_subtotal') || 0);
        data.invoice.tax_amount = parseFloat(readValue('invoice_tax_amount') || 0);
        data.invoice.total = parseFloat(readValue('invoice_total') || 0);
        data.invoice.summary = readValue('invoice_summary');
        data.invoice.notes = readValue('invoice_notes');

        data.supplier.name = readValue('supplier_name');
        data.supplier.tax_id = readValue('supplier_tax_id');
        data.supplier.email = readValue('supplier_email');
        data.supplier.phone = readValue('supplier_phone');
        data.supplier.address = readValue('supplier_address');

        const selectedSupplier = document.getElementById('supplier_match_select');
        if (selectedSupplier) {
            data.supplier.matched_supplier_id = selectedSupplier.value;
        } else if ((data.supplier.match_status || 'not_found') === 'not_found') {
            if (!window.confirm('Supplier not found. Do you want AiScan to create a new supplier with the extracted data?')) {
                return;
            }
            data.supplier.create_if_missing = true;
        }

        data.lines = Array.from(document.querySelectorAll(`#${selectors.linesBody} tr`)).map((row) => {
            const line = {};
            row.querySelectorAll('[data-field]').forEach((input) => {
                line[input.dataset.field] = input.type === 'number' ? parseFloat(input.value || 0) : input.value;
            });
            return line;
        });

        data._upload = {
            mime_type: state.mimeType,
            original_name: state.originalName,
            tmp_file: state.tmpFile,
        };

        setStatus('Saving purchase invoice…', 'info');

        const params = new URLSearchParams({
            action: 'apply',
            invoice_id: state.invoiceId || '',
            multireqtoken: getRequestToken(),
        });

        fetch('AiScanInvoice?' + params.toString(), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data),
        })
            .then((response) => response.json())
            .then((result) => {
                if (result.error) {
                    throw new Error(result.error);
                }

                setStatus('Invoice saved successfully.', 'success');
                bootstrap.Modal.getInstance(document.getElementById(selectors.modalId))?.hide();
                setTimeout(() => window.location.href = 'EditFacturaProveedor?code=' + encodeURIComponent(result.invoice_id), 300);
            })
            .catch((error) => setStatus(error.message, 'danger'));
    }

    function readValue(id) {
        return document.getElementById(id)?.value || '';
    }

    function setStatus(message, level = '') {
        const status = document.getElementById(selectors.status);
        if (!message) {
            status.innerHTML = '';
            return;
        }

        status.innerHTML = `<div class="alert alert-${level || 'secondary'} py-2 small mb-0">${escapeHtml(message)}</div>`;
    }

    function updateBrowserPromptSupport() {
        const supported = isBrowserPromptApiSupported();
        const badge = document.getElementById(selectors.supportBadge);
        if (!badge) {
            return;
        }

        badge.className = 'badge ' + (supported ? 'text-bg-success' : 'text-bg-secondary');
        badge.textContent = supported ? 'Browser Prompt API available' : 'Browser Prompt API unavailable';
    }

    function isBrowserPromptApiSupported() {
        try {
            return Boolean(window.ai && (
                typeof window.ai.createTextSession === 'function'
                || typeof window.ai.promptManager === 'object'
                || typeof window.ai.languageModel === 'object'
            ));
        } catch (error) {
            if (window.console && typeof window.console.debug === 'function') {
                window.console.debug('AiScan Browser Prompt API detection failed.', error);
            }
            return false;
        }
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function escapeAttribute(value) {
        return escapeHtml(value).replace(/"/g, '&quot;');
    }
})();
