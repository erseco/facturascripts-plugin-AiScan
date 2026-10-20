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
                // Upload failure without a usable file — still offer manual shell if we have tmpFile.
                if (doc.tmpFile) {
                    applyManualEntryFallback(doc, {
                        message: doc.error || trans('aiscan-scan-failed-manual-entry'),
                    });
                    // Fall through by re-entering with extractedData set.
                    renderReviewPanel(doc);
                    return;
                }
                review.innerHTML = `<div class="text-center p-4"><i class="fa-solid fa-times-circle fa-2x text-danger mb-2"></i><p class="text-danger">${escapeHtml(doc.error || trans('aiscan-status-failed'))}</p>
                    <p class="text-muted small mt-2">${escapeHtml(trans('aiscan-scan-failed-manual-entry'))}</p></div>`;
            } else {
                review.innerHTML = `<p class="text-muted mb-0">${escapeHtml(trans('aiscan-initial-review-message'))}</p>`;
            }
            return;
        }

        const data = doc.extractedData;
        const invoice = data.invoice || {};
        const supplier = data.supplier || {};
        const lines = Array.isArray(data.lines) ? data.lines : [];
        const validationErrors = getValidationWarnings(data);
        const confidence = data.confidence || {};

        review.innerHTML = '';

        // Non-blocking banner when automatic scan failed or manual entry was chosen (#67)
        if (doc.scanFailed || data._scan_failed) {
            const msg = doc.scanFailedMessage
                || (Array.isArray(data.warnings) && data.warnings[0])
                || trans('aiscan-scan-failed-manual-entry');
            review.insertAdjacentHTML('beforeend', `
                <div class="alert alert-warning py-2" id="aiscan-scan-failed-banner" role="status">
                    <strong class="small"><i class="fa-solid fa-triangle-exclamation me-1"></i>${escapeHtml(trans('aiscan-manual-entry-title'))}</strong>
                    <p class="mb-0 small">${escapeHtml(msg)}</p>
                </div>
            `);
        }

        review.insertAdjacentHTML('beforeend', `
            <div id="aiscan-validation-warnings" class="${validationErrors.length > 0 ? 'alert alert-danger py-2' : 'd-none'}">
                ${renderValidationWarningsHtml(validationErrors)}
            </div>
        `);

        const aiWarnings = Array.isArray(data.warnings)
            ? data.warnings.filter(w => w && !(doc.scanFailed && w === doc.scanFailedMessage))
            : [];
        if (aiWarnings.length > 0 && !doc.scanFailed) {
            review.insertAdjacentHTML('beforeend', `
                <div class="alert alert-info py-2">
                    <strong class="small"><i class="fa-solid fa-circle-info me-1"></i>${escapeHtml(trans('aiscan-ai-notes'))}</strong>
                    <ul class="mb-0 small">${aiWarnings.map(w => `<li>${escapeHtml(w)}</li>`).join('')}</ul>
                </div>
            `);
        }

        const partyType = resolveDocPartyType(doc);
        const supplierMatched = supplier.match_status === 'matched' || supplier.match_status === 'selected' || supplier.match_status === 'created';
        const badgeLabels = {
            matched: trans('aiscan-detected'),
            selected: trans('aiscan-selected'),
            created: trans('aiscan-supplier-created'),
        };
        const badgeLabel = badgeLabels[supplier.match_status] || badgeLabels.matched;
        const partyBadge = partyType === PARTY_CREDITOR
            ? trans('aiscan-party-type-creditor')
            : trans('aiscan-party-type-supplier');
        const supplierSummary = supplierMatched
            ? ` <span class="text-muted fw-normal ms-2">&middot; ${escapeHtml(supplier.matched_name || supplier.name || '')} (${escapeHtml(supplier.tax_id || '')})</span> <span class="badge text-bg-success ms-1">${escapeHtml(badgeLabel)}</span> <span class="badge text-bg-info ms-1">${escapeHtml(partyBadge)}</span>`
            : (supplier.match_status === 'not_found' ? ` <span class="badge text-bg-warning ms-2">${escapeHtml(trans('aiscan-no-supplier-matched'))}</span> <span class="badge text-bg-info ms-1">${escapeHtml(partyBadge)}</span>` : ` <span class="badge text-bg-info ms-1">${escapeHtml(partyBadge)}</span>`);

        const partyTypeSelectHtml = `
            <div class="mb-2">
                <label class="form-label small mb-1" for="supplier_party_type">${escapeHtml(trans('aiscan-party-type'))}</label>
                <select class="form-select form-select-sm" id="supplier_party_type">
                    <option value="${PARTY_SUPPLIER}"${partyType === PARTY_SUPPLIER ? ' selected' : ''}>${escapeHtml(trans('aiscan-party-type-supplier'))}</option>
                    <option value="${PARTY_CREDITOR}"${partyType === PARTY_CREDITOR ? ' selected' : ''}>${escapeHtml(trans('aiscan-party-type-creditor'))}</option>
                </select>
            </div>`;

        const supplierBody = supplierMatched
            ? `${partyTypeSelectHtml}
                <div class="d-flex gap-2 mb-1">
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
            : `${partyTypeSelectHtml}
                <div class="d-flex gap-2 mb-1">
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

        if (supplierMatched && supplier.matched_supplier_id) {
            review.appendChild(buildDefaultProductSection(supplier));
        }

        review.appendChild(buildSection(trans('aiscan-section-invoice'), `
            <div class="d-flex gap-2 mb-1">
                <div style="flex:2">${buildInput(trans('number'), 'invoice_number', invoice.number || '', 'text', null, confidence.invoice_number)}</div>
                <div style="flex:1">${buildInput(trans('date'), 'invoice_issue_date', invoice.issue_date || '', 'date', null, confidence.issue_date)}</div>
                <div style="flex:1">${buildInput(trans('expiration'), 'invoice_due_date', invoice.due_date || '', 'date')}</div>
            </div>
            <div class="d-flex gap-2 mb-1">
                <div style="flex:2">
                    <input class="form-control form-control-sm text-truncate" id="invoice_summary" type="text" value="${escapeAttr(invoice.summary || '')}" placeholder="${escapeAttr(trans('summary'))}">
                </div>
                <div style="flex:1">
                    <label class="form-label small mb-1" for="invoice_codpago">${escapeHtml(trans('aiscan-payment-method'))}</label>
                    ${buildPaymentMethodSelect(invoice.codpago || doc._codpago || state.codpago)}
                </div>
            </div>
            ${invoice.payment_terms ? `<input type="hidden" id="invoice_payment_terms" value="${escapeAttr(invoice.payment_terms)}">` : ''}
        `));

        const docMode = doc._importMode || state.importMode;
        if (docMode === 'total') {
            review.appendChild(buildLinesSection(lines.length ? lines : buildTotalLines(data)));
        } else {
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
            <div id="aiscan-rounding-alert" class="d-none mt-2"></div>
        `));

        review.insertAdjacentHTML('beforeend', `
            <div id="aiscan-mark-ready-hint" class="alert alert-warning py-2 d-none" role="status"></div>
            <div class="d-flex justify-content-end gap-2 mt-3 mb-2">
                <button type="button" class="btn btn-success" id="aiscan-mark-ready-btn">
                    <i class="fa-solid fa-check me-1"></i>${escapeHtml(trans('aiscan-mark-ready'))}
                </button>
            </div>
        `);

        document.getElementById('aiscan-mark-ready-btn')?.addEventListener('click', markCurrentReady);
        bindSupplierSearch();
        bindReviewFormGate();
        updateMarkReadyButton(data);
    }

    /**
     * Keep mark-ready disabled until vital fields are filled (#78).
     */
    function bindReviewFormGate() {
        const review = document.getElementById('aiscan-review');
        if (!review || review.dataset.markReadyGateBound === '1') {
            return;
        }
        review.dataset.markReadyGateBound = '1';
        review.addEventListener('input', () => {
            updateValidationWarnings();
        });
        review.addEventListener('change', () => {
            updateValidationWarnings();
        });
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

    /**
     * Issue #56: umbrales visuales de confianza.
     * < 50% rojo, 50–80% amarillo, ≥ 80% verde.
     * Campo vacío → confianza efectiva 0 (rojo 0%), aunque la IA diga 70%.
     */
    function resolveFieldConfidence(value, confidence) {
        if (value == null || (typeof value === 'string' && value.trim() === '')) {
            return 0;
        }
        if (confidence == null || confidence === '') {
            return null;
        }
        let n = Number(confidence);
        if (!Number.isFinite(n)) {
            return 0;
        }
        if (n > 1 && n <= 100) {
            n = n / 100;
        }
        if (n < 0) {
            return 0;
        }
        if (n > 1) {
            return 1;
        }
        return n;
    }

    function confidenceBadgeClass(confidence) {
        const n = Number(confidence);
        if (!Number.isFinite(n) || n < 0.5) {
            return 'text-bg-danger';
        }
        if (n < 0.8) {
            return 'text-bg-warning';
        }
        return 'text-bg-success';
    }

    function buildConfidenceBadge(value, confidence) {
        const resolved = resolveFieldConfidence(value, confidence);
        if (resolved == null) {
            return '';
        }
        const pct = Math.round(resolved * 100);
        return ` <span class="badge ${confidenceBadgeClass(resolved)}" title="${escapeAttr(trans('confidence'))}">${pct}%</span>`;
    }

    function buildInput(label, id, value, type, step, confidence) {
        const badge = buildConfidenceBadge(value, confidence);
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

