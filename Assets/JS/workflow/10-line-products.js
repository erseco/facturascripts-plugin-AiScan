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
        const search = createLatestSearch('search-products');

        const doSearch = () => {
            const query = input.value.trim();
            if (query.length < MIN_AUTOCOMPLETE_QUERY_LENGTH) {
                resultsDiv.innerHTML = '';
                return;
            }
            search(query)
                .then(items => {
                    if (items === null) {
                        return;
                    }
                    resultsDiv.innerHTML = items.length
                        ? buildProductOptionsHtml(items, '', 'aiscan-product-modal-pick')
                        : autocompleteNoResultsHtml();
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
            setLineProductMatch(productSearchTargetRow, pick.dataset.ref || '');
            bootstrap.Modal.getInstance(document.getElementById('aiscan-find-product-modal'))?.hide();
            productSearchTargetRow = null;
        });
    }

    function openProductSearchModal(row) {
        ensureProductSearchModal();
        productSearchTargetRow = row;
        const input = document.getElementById('aiscan-product-modal-input');
        const resultsDiv = document.getElementById('aiscan-product-modal-results');
        const desc = row.querySelector('[data-field="descripcion"]')?.value || '';
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
        const refSource = line.referencia_source || '';
        const matchBadge = buildProductMatchBadge(ref, refSource);
        const modalId = 'aiscan-line-modal-' + index;
        const resultsId = 'aiscan-line-product-results-' + index;
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
        const suplido = line.suplido && line.suplido !== '0' && line.suplido !== 'false' ? '1' : '0';
        return `<div class="aiscan-line-row d-flex gap-1 align-items-center py-1 border-bottom" data-line-index="${index}" data-tax-rate="${taxRate}" data-tax-code="${escapeAttr(taxCode)}" data-irpf-code="${escapeAttr(irpfCode)}">
            <div class="aiscan-product-cell" style="flex:4;position:relative">
                <div class="input-group input-group-sm">
                    <input class="form-control form-control-sm aiscan-product-autocomplete-input" data-field="descripcion" value="${escapeAttr(desc)}" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-haspopup="listbox" aria-controls="${resultsId}">
                    <input type="hidden" data-field="referencia" value="${escapeAttr(ref)}">
                    <button class="btn btn-info btn-sm aiscan-product-match-btn" type="button" title="${escapeAttr(trans('aiscan-search-product'))}"><i class="fa-solid fa-book fa-fw"></i></button>
                    <span class="input-group-text p-0 px-1 aiscan-ref-badge">${matchBadge}</span>
                </div>
                <div id="${resultsId}" class="list-group position-absolute w-100 shadow-sm d-none aiscan-product-autocomplete-results" role="listbox" style="top:100%;left:0;z-index:1080;max-height:200px;overflow-y:auto"></div>
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
            <input type="hidden" data-field="codretencion" value="${escapeAttr(irpfCode)}">
            <input type="hidden" data-field="excepcioniva" value="${escapeAttr(excepcion)}">
            <input type="hidden" data-field="suplido" value="${escapeAttr(suplido)}">
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header py-2" style="cursor:grab">
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
                                <div class="col-6">
                                    <label class="form-label small mb-1" for="${modalId}-discount-pct">${escapeHtml(trans('aiscan-discount-percent'))}</label>
                                    <div class="input-group input-group-sm">
                                        <input id="${modalId}-discount-pct" type="number" class="form-control aiscan-modal-discount" min="0" max="100" step="any" value="${escapeAttr(dto)}" aria-describedby="${modalId}-discount-help">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1" for="${modalId}-discount-amt">${escapeHtml(trans('aiscan-discount-amount'))}</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="fa-solid fa-euro-sign fa-fw"></i></span>
                                        <input id="${modalId}-discount-amt" type="number" class="form-control aiscan-modal-discount-amount" min="0" step="any" value="">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div id="${modalId}-discount-help" class="form-text">${escapeHtml(trans('aiscan-discount-amount-help'))}</div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">${escapeHtml(trans('tax'))}</label>
                                    ${buildTaxSelect(taxRate, taxCode).replace('class="form-select form-select-sm" data-field="tax_code"', 'class="form-select form-select-sm aiscan-modal-tax"')}
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">IRPF</label>
                                    ${buildWithholdingSelect(irpfRate, irpfCode).replace('class="form-select form-select-sm" data-field="irpf"', 'class="form-select form-select-sm aiscan-modal-irpf"')}
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">${escapeHtml(trans('aiscan-tax-exception'))}</label>
                                    ${buildTaxExceptionSelect(excepcion)}
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
                            <button type="button" class="btn btn-sm btn-primary aiscan-modal-accept">${escapeHtml(trans('accept'))}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
    }

    function buildProductMatchBadge(reference, source) {
        if (!reference) {
            return `<span class="badge text-bg-secondary" data-bs-toggle="tooltip" data-bs-placement="top" title="${escapeAttr(trans('aiscan-no-product'))}"><i class="fa-solid fa-unlink"></i></span>`;
        }
        if (source === 'history' || source === 'pinned') {
            const titleKey = source === 'pinned'
                ? 'aiscan-product-suggested-pinned'
                : 'aiscan-product-suggested-history';
            const icon = source === 'pinned' ? 'fa-thumbtack' : 'fa-clock-rotate-left';
            return `<span class="badge text-bg-warning" data-bs-toggle="tooltip" data-bs-placement="top" title="${escapeAttr(trans(titleKey) + ': ' + reference)}"><i class="fa-solid ${icon}"></i></span>`;
        }
        return `<span class="badge text-bg-success" data-bs-toggle="tooltip" data-bs-placement="top" title="${escapeAttr(reference)}"><i class="fa-solid fa-link"></i></span>`;
    }

    function setLineProductMatch(row, reference) {
        const refInput = row?.querySelector('[data-field="referencia"]');
        if (refInput) {
            refInput.value = reference || '';
        }

        const badge = row?.querySelector('.aiscan-ref-badge');
        if (badge) {
            badge.innerHTML = buildProductMatchBadge(reference || '');
            const tooltip = badge.querySelector('[data-bs-toggle="tooltip"]');
            if (tooltip) {
                new bootstrap.Tooltip(tooltip);
            }
        }
    }

    function focusNextLineField(row, currentField) {
        const fields = [
            row?.querySelector('[data-field="descripcion"]'),
            row?.querySelector('[data-field="cantidad"]'),
            row?.querySelector('[data-field="pvpunitario"]'),
            row?.querySelector('.aiscan-line-detail-btn'),
            row?.querySelector('.aiscan-delete-line'),
        ].filter(Boolean);
        const currentIndex = fields.indexOf(currentField);
        const nextField = fields[currentIndex + 1] || null;
        if (nextField && typeof nextField.focus === 'function') {
            nextField.focus();
        }
    }

    function bindLineProductAutocomplete(row) {
        if (!row || row.dataset.productAutocompleteBound === '1') {
            return;
        }

        row.dataset.productAutocompleteBound = '1';

        const input = row.querySelector('.aiscan-product-autocomplete-input');
        const resultsDiv = row.querySelector('.aiscan-product-autocomplete-results');
        if (!input || !resultsDiv) {
            return;
        }

        let timer = null;
        let activeIndex = -1;
        const search = createLatestSearch('search-products');
        const resultsId = resultsDiv.id;

        const closeResults = () => {
            closeAutocomplete(resultsDiv, input);
            activeIndex = -1;
        };

        const renderResults = (items) => {
            if (items.length < 1) {
                closeResults();
                return;
            }

            resultsDiv.innerHTML = buildProductOptionsHtml(items, resultsId);
            resultsDiv.classList.remove('d-none');
            input.setAttribute('aria-expanded', 'true');
            activeIndex = 0;
            highlightAutocompleteOption(resultsDiv, input, activeIndex);
        };

        const doSearch = () => {
            const query = input.value.trim();
            if (query.length < MIN_AUTOCOMPLETE_QUERY_LENGTH) {
                closeResults();
                return;
            }

            search(query)
                .then(items => {
                    if (items !== null) {
                        renderResults(items);
                    }
                })
                .catch(closeResults);
        };

        const selectOption = (option, moveFocus = false) => {
            if (!option) {
                return;
            }

            setLineProductMatch(row, option.dataset.ref || '');
            closeResults();

            if (moveFocus) {
                focusNextLineField(row, input);
            }
        };

        input.addEventListener('input', () => {
            clearTimeout(timer);
            setLineProductMatch(row, '');
            timer = setTimeout(doSearch, 250);
        });

        input.addEventListener('focus', () => {
            if (input.value.trim().length >= MIN_AUTOCOMPLETE_QUERY_LENGTH) {
                clearTimeout(timer);
                timer = setTimeout(doSearch, 0);
            }
        });

        input.addEventListener('keydown', e => {
            const options = Array.from(resultsDiv.querySelectorAll(AUTOCOMPLETE_OPTION_SELECTOR));
            const action = resolveAutocompleteKeyAction(
                e.key,
                !resultsDiv.classList.contains('d-none'),
                activeIndex,
                options.length
            );
            if (!action) {
                return;
            }

            if (action.preventDefault) {
                e.preventDefault();
            }

            if (action.type === 'highlight') {
                activeIndex = action.index;
                highlightAutocompleteOption(resultsDiv, input, activeIndex);
                return;
            }

            if (action.type === 'select') {
                selectOption(options[action.index], action.moveFocus);
                return;
            }

            if (action.type === 'close') {
                closeResults();
            }
        });

        resultsDiv.addEventListener('mousedown', e => {
            if (e.target.closest(AUTOCOMPLETE_OPTION_SELECTOR)) {
                e.preventDefault();
            }
        });

        resultsDiv.addEventListener('click', e => {
            const option = e.target.closest(AUTOCOMPLETE_OPTION_SELECTOR);
            if (!option) {
                return;
            }

            selectOption(option);
        });

        row.addEventListener('focusout', e => {
            if (row.contains(e.relatedTarget)) {
                return;
            }

            setTimeout(() => {
                if (!row.contains(document.activeElement)) {
                    closeResults();
                }
            }, 0);
        });
    }

