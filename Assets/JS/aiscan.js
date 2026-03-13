/**
 * AiScan - AI-powered invoice scanning frontend
 * This file is part of AiScan plugin for FacturaScripts.
 */

'use strict';

const AiScan = (() => {
    let currentTmpFile = null;
    let currentMimeType = null;
    let invoiceId = null;

    function init(idinvoice) {
        invoiceId = idinvoice;
        bindEvents();
    }

    function bindEvents() {
        const dropArea = document.getElementById('aiscan-drop-area');
        const fileInput = document.getElementById('aiscan-file-input');
        const scanBtn = document.getElementById('aiscan-scan-btn');
        const acceptBtn = document.getElementById('aiscan-accept-btn');

        if (!dropArea) return;

        dropArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropArea.classList.add('aiscan-drag-over');
        });

        dropArea.addEventListener('dragleave', () => {
            dropArea.classList.remove('aiscan-drag-over');
        });

        dropArea.addEventListener('drop', (e) => {
            e.preventDefault();
            dropArea.classList.remove('aiscan-drag-over');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFile(files[0]);
            }
        });

        dropArea.addEventListener('click', () => fileInput && fileInput.click());

        if (fileInput) {
            fileInput.addEventListener('change', () => {
                if (fileInput.files.length > 0) {
                    handleFile(fileInput.files[0]);
                }
            });
        }

        if (scanBtn) {
            scanBtn.addEventListener('click', () => {
                if (currentTmpFile) {
                    analyzeDocument();
                }
            });
        }

        if (acceptBtn) {
            acceptBtn.addEventListener('click', () => acceptExtracted());
        }
    }

    function handleFile(file) {
        showPreview(file);
        uploadFile(file);
    }

    function showPreview(file) {
        const previewContainer = document.getElementById('aiscan-preview');
        if (!previewContainer) return;

        previewContainer.innerHTML = '';

        const url = URL.createObjectURL(file);

        if (file.type === 'application/pdf') {
            const iframe = document.createElement('iframe');
            iframe.src = url;
            iframe.className = 'aiscan-preview-iframe';
            iframe.style.width = '100%';
            iframe.style.height = '400px';
            previewContainer.appendChild(iframe);
        } else if (file.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.src = url;
            img.className = 'aiscan-preview-img img-fluid';
            img.style.maxHeight = '400px';
            previewContainer.appendChild(img);
        }

        const nameEl = document.getElementById('aiscan-filename');
        if (nameEl) nameEl.textContent = file.name;
    }

    function uploadFile(file) {
        setStatus('uploading');

        const formData = new FormData();
        formData.append('invoice_file', file);

        fetch('AiScanInvoice?action=upload', {
            method: 'POST',
            body: formData,
        })
            .then((r) => r.json())
            .then((data) => {
                if (data.error) {
                    setError(data.error);
                    return;
                }
                currentTmpFile = data.tmp_file;
                currentMimeType = data.mime_type;
                setStatus('uploaded');

                const scanBtn = document.getElementById('aiscan-scan-btn');
                if (scanBtn) scanBtn.disabled = false;

                if (data.auto_scan) {
                    analyzeDocument();
                }
            })
            .catch((err) => setError('Upload failed: ' + err.message));
    }

    function analyzeDocument() {
        if (!currentTmpFile) return;

        setStatus('analyzing');

        const params = new URLSearchParams({
            action: 'analyze',
            tmp_file: currentTmpFile,
            mime_type: currentMimeType,
        });

        fetch('AiScanInvoice?' + params.toString())
            .then((r) => r.json())
            .then((data) => {
                if (data.error) {
                    setError(data.error);
                    return;
                }
                setStatus('done');
                renderReviewForm(data.data);
            })
            .catch((err) => setError('Analysis failed: ' + err.message));
    }

    function renderReviewForm(data) {
        const reviewContainer = document.getElementById('aiscan-review');
        if (!reviewContainer) return;

        reviewContainer.innerHTML = '';

        const invoice = data.invoice || {};
        const supplier = data.supplier || {};
        const lines = data.lines || [];
        const errors = data._validation_errors || [];

        if (errors.length > 0) {
            const alertEl = document.createElement('div');
            alertEl.className = 'alert alert-warning';
            alertEl.innerHTML = '<strong>Validation warnings:</strong><ul>'
                + errors.map((e) => '<li>' + escapeHtml(e) + '</li>').join('')
                + '</ul>';
            reviewContainer.appendChild(alertEl);
        }

        // Supplier section
        const supplierSection = buildSection('Supplier', [
            buildField('Name', supplier.name || '', 'supplier_name'),
            buildField('Tax ID', supplier.tax_id || '', 'supplier_tax_id'),
            buildSupplierMatchBadge(supplier),
        ]);
        reviewContainer.appendChild(supplierSection);

        // Invoice section
        const invoiceSection = buildSection('Invoice Details', [
            buildField('Number', invoice.number || '', 'invoice_number'),
            buildField('Issue Date', invoice.issue_date || '', 'invoice_date'),
            buildField('Due Date', invoice.due_date || '', 'invoice_due_date'),
            buildField('Subtotal', invoice.subtotal || 0, 'invoice_subtotal'),
            buildField('Tax Amount', invoice.tax_amount || 0, 'invoice_tax'),
            buildField('Total', invoice.total || 0, 'invoice_total'),
        ]);
        reviewContainer.appendChild(invoiceSection);

        // Lines section
        if (lines.length > 0) {
            const linesSection = buildLinesSection(lines);
            reviewContainer.appendChild(linesSection);
        }

        // Provider info
        const providerBadge = document.createElement('small');
        providerBadge.className = 'text-muted';
        providerBadge.textContent = 'Analyzed by: ' + (data._provider || 'unknown');
        reviewContainer.appendChild(providerBadge);

        // Accept button
        const acceptBtn = document.getElementById('aiscan-accept-btn');
        if (acceptBtn) {
            acceptBtn.disabled = false;
            acceptBtn.dataset.extractedData = JSON.stringify(data);
        }
    }

    function buildSection(title, children) {
        const section = document.createElement('div');
        section.className = 'card mb-3';

        const header = document.createElement('div');
        header.className = 'card-header';
        header.textContent = title;
        section.appendChild(header);

        const body = document.createElement('div');
        body.className = 'card-body';
        children.forEach((child) => child && body.appendChild(child));
        section.appendChild(body);

        return section;
    }

    function buildField(label, value, id) {
        const group = document.createElement('div');
        group.className = 'form-group row mb-2';

        const lbl = document.createElement('label');
        lbl.className = 'col-sm-4 col-form-label col-form-label-sm';
        lbl.textContent = label;
        group.appendChild(lbl);

        const col = document.createElement('div');
        col.className = 'col-sm-8';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm';
        input.id = 'aiscan-' + id;
        input.value = value !== null && value !== undefined ? String(value) : '';
        col.appendChild(input);
        group.appendChild(col);

        return group;
    }

    function buildSupplierMatchBadge(supplier) {
        const status = supplier.match_status || 'not_found';
        const div = document.createElement('div');
        div.className = 'mb-2';

        const badge = document.createElement('span');
        badge.className = 'badge badge-' + (status === 'matched' ? 'success' : status === 'ambiguous' ? 'warning' : 'secondary');
        badge.textContent = status === 'matched'
            ? '✓ Matched: ' + (supplier.matched_name || '')
            : status === 'ambiguous'
                ? '⚠ Multiple matches found'
                : '✗ Not found in system';
        div.appendChild(badge);

        if (status === 'ambiguous' && supplier.candidates) {
            const select = document.createElement('select');
            select.className = 'form-control form-control-sm mt-1';
            select.id = 'aiscan-supplier-select';
            supplier.candidates.forEach((c) => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name + ' (' + c.tax_id + ')';
                select.appendChild(opt);
            });
            div.appendChild(select);
        }

        return div;
    }

    function buildLinesSection(lines) {
        const section = document.createElement('div');
        section.className = 'card mb-3';

        const header = document.createElement('div');
        header.className = 'card-header';
        header.textContent = 'Line Items (' + lines.length + ')';
        section.appendChild(header);

        const body = document.createElement('div');
        body.className = 'card-body p-0';

        const table = document.createElement('table');
        table.className = 'table table-sm table-striped mb-0';

        const thead = document.createElement('thead');
        thead.innerHTML = '<tr><th>Description</th><th>Qty</th><th>Unit Price</th><th>Tax %</th><th>Total</th></tr>';
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        lines.forEach((line) => {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>' + escapeHtml(line.description || '') + '</td>'
                + '<td>' + (line.quantity || 0) + '</td>'
                + '<td>' + (line.unit_price || 0) + '</td>'
                + '<td>' + (line.tax_rate || 0) + '%</td>'
                + '<td>' + (line.line_total || 0) + '</td>';
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        body.appendChild(table);
        section.appendChild(body);

        return section;
    }

    function acceptExtracted() {
        const acceptBtn = document.getElementById('aiscan-accept-btn');
        if (!acceptBtn || !acceptBtn.dataset.extractedData) return;

        const data = JSON.parse(acceptBtn.dataset.extractedData);

        // Patch in selected supplier if ambiguous
        const supplierSelect = document.getElementById('aiscan-supplier-select');
        if (supplierSelect && data.supplier) {
            data.supplier.matched_supplier_id = supplierSelect.value;
        }

        // Patch edited invoice fields
        const fieldMap = {
            'aiscan-invoice_number': ['invoice', 'number'],
            'aiscan-invoice_date': ['invoice', 'issue_date'],
            'aiscan-invoice_due_date': ['invoice', 'due_date'],
            'aiscan-invoice_total': ['invoice', 'total'],
            'aiscan-supplier_name': ['supplier', 'name'],
            'aiscan-supplier_tax_id': ['supplier', 'tax_id'],
        };

        Object.keys(fieldMap).forEach((elId) => {
            const el = document.getElementById(elId);
            if (el) {
                const [section, field] = fieldMap[elId];
                if (data[section]) data[section][field] = el.value;
            }
        });

        // Send to backend
        setStatus('saving');

        fetch('AiScanInvoice?action=apply&invoice_id=' + (invoiceId || ''), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data),
        })
            .then((r) => r.json())
            .then((result) => {
                if (result.error) {
                    setError(result.error);
                    return;
                }
                setStatus('saved');
                // Close modal and reload
                const modal = document.getElementById('modal-aiscan');
                if (modal && typeof bootstrap !== 'undefined') {
                    bootstrap.Modal.getInstance(modal)?.hide();
                }
                setTimeout(() => window.location.reload(), 500);
            })
            .catch((err) => setError('Save failed: ' + err.message));
    }

    function setStatus(status) {
        const statusEl = document.getElementById('aiscan-status');
        if (!statusEl) return;

        const messages = {
            uploading: '<span class="spinner-border spinner-border-sm"></span> Uploading...',
            uploaded: '<span class="text-success">✓ File uploaded</span>',
            analyzing: '<span class="spinner-border spinner-border-sm"></span> Analyzing with AI...',
            done: '<span class="text-success">✓ Analysis complete</span>',
            saving: '<span class="spinner-border spinner-border-sm"></span> Saving...',
            saved: '<span class="text-success">✓ Saved!</span>',
        };

        statusEl.innerHTML = messages[status] || '';
    }

    function setError(message) {
        const statusEl = document.getElementById('aiscan-status');
        if (statusEl) {
            statusEl.innerHTML = '<span class="text-danger">✗ ' + escapeHtml(message) + '</span>';
        }
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    return {init};
})();
