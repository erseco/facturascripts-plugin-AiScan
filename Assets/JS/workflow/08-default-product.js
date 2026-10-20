    function formatDefaultProductLabel(referencia, description) {
        const ref = String(referencia || '').trim();
        const desc = String(description || '').trim();
        if (ref && desc) {
            return ref + ' — ' + desc;
        }
        return ref || desc;
    }

    function getDefaultProductPickerEls() {
        return {
            input: document.getElementById('aiscan-default-product-search'),
            clearBtn: document.getElementById('aiscan-default-product-clear'),
            searchBtn: document.getElementById('aiscan-product-search-btn'),
            resultsDiv: document.getElementById('aiscan-product-results'),
            statusEl: document.getElementById('aiscan-default-product-status'),
        };
    }

    /**
     * Issue #76: muestra el producto fijado en el único control de búsqueda,
     * sin campo de referencia ni botón de guardado separados.
     * data-committed-* guarda la selección persistida para poder restaurarla (Esc).
     */
    function setDefaultProductPickerValue(referencia, description, codproveedor) {
        const {input, clearBtn} = getDefaultProductPickerEls();
        if (!input) {
            return;
        }
        const ref = String(referencia || '').trim();
        const desc = String(description || '').trim();
        input.dataset.codproveedor = codproveedor || input.dataset.codproveedor || '';
        input.dataset.referencia = ref;
        input.dataset.description = desc;
        input.dataset.committedRef = ref;
        input.dataset.committedDesc = desc;
        input.value = formatDefaultProductLabel(ref, desc);
        if (clearBtn) {
            clearBtn.classList.toggle('d-none', ref === '');
            clearBtn.disabled = ref === '';
        }
    }

    function buildDefaultProductSection(supplier) {
        const codproveedor = supplier.matched_supplier_id || '';
        const resultsId = 'aiscan-product-results';
        const body = `
            <div class="aiscan-default-product-picker">
                <label class="form-label small mb-1" for="aiscan-default-product-search">${escapeHtml(trans('aiscan-default-product-help'))}</label>
                <div class="input-group input-group-sm">
                    <input type="text"
                        class="form-control"
                        id="aiscan-default-product-search"
                        placeholder="${escapeAttr(trans('aiscan-search-product'))}"
                        autocomplete="off"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-haspopup="listbox"
                        aria-controls="${resultsId}"
                        data-codproveedor="${escapeAttr(codproveedor)}"
                        data-referencia=""
                        data-description="">
                    <button type="button"
                        class="btn btn-outline-secondary d-none"
                        id="aiscan-default-product-clear"
                        title="${escapeAttr(trans('aiscan-clear-default-product'))}"
                        aria-label="${escapeAttr(trans('aiscan-clear-default-product'))}"
                        disabled>
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                    <button type="button"
                        class="btn btn-outline-secondary"
                        id="aiscan-product-search-btn"
                        title="${escapeAttr(trans('aiscan-search-product'))}"
                        aria-label="${escapeAttr(trans('aiscan-search-product'))}">
                        <i class="fa-solid fa-search" aria-hidden="true"></i>
                    </button>
                </div>
                <div id="${resultsId}"
                    class="list-group mt-1 aiscan-default-product-results d-none"
                    role="listbox"
                    style="max-height:150px;overflow-y:auto"></div>
                <div class="small text-muted mt-1" id="aiscan-default-product-status" aria-live="polite"></div>
            </div>
        `;

        const section = buildSection(trans('aiscan-default-product'), body, {collapsed: true});

        setTimeout(() => {
            bindDefaultProductPicker();
            if (codproveedor) {
                loadDefaultProduct(codproveedor);
            }
        }, 0);

        return section;
    }

    async function loadDefaultProduct(codproveedor) {
        try {
            const params = new URLSearchParams({action: 'get-supplier-default-product', codproveedor});
            const response = await apiFetch('AiScanInvoice?' + params.toString());
            const data = await readJsonResponse(response);
            if (data.found) {
                setDefaultProductPickerValue(data.referencia, data.description || '', codproveedor);
                // Issue #69: reaplicar el pin a las líneas del documento actual
                // (incluido modo total con líneas sintéticas).
                const doc = currentDoc();
                if (doc?.extractedData) {
                    doc.extractedData._product_suggestion = {
                        referencia: data.referencia,
                        description: data.description || '',
                        source: 'pinned',
                    };
                    applyPinnedProductToLines(doc, data.referencia);
                    refreshLineProductBadges(doc);
                }
            } else {
                setDefaultProductPickerValue('', '', codproveedor);
            }
        } catch (e) {
            // silent
        }
    }

    /**
     * Issue #69: escribe la referencia fijada en líneas sin producto (o con
     * sugerencia histórica previa), sin pisar un match real del usuario/IA.
     */
    function applyPinnedProductToLines(doc, referencia) {
        if (!doc?.extractedData || !referencia) {
            return;
        }
        if (!Array.isArray(doc.extractedData.lines)) {
            doc.extractedData.lines = [];
        }
        // En modo total sin líneas, generar sintéticas y enlazar el producto.
        const mode = doc._importMode || state.importMode;
        if (mode === 'total' && doc.extractedData.lines.length === 0) {
            doc.extractedData.lines = buildTotalLines(doc.extractedData);
        }
        doc.extractedData.lines = doc.extractedData.lines.map(line => {
            const ref = String(line.referencia || line.sku || '').trim();
            if (ref !== '' && line.referencia_source !== 'history' && line.referencia_source !== 'pinned') {
                return line;
            }
            return {
                ...line,
                referencia: referencia,
                referencia_source: 'pinned',
            };
        });
    }

    function refreshLineProductBadges(doc) {
        // Si el panel de revisión está montado, re-sincronizar hidden inputs de
        // referencia sin re-renderizar todo el formulario (evita perder foco).
        const rows = document.querySelectorAll('#aiscan-lines-body .aiscan-line-row');
        if (!rows.length || !Array.isArray(doc?.extractedData?.lines)) {
            return;
        }
        rows.forEach((row, index) => {
            const line = doc.extractedData.lines[index];
            if (!line) {
                return;
            }
            const refInput = row.querySelector('[data-field="referencia"]');
            if (refInput && line.referencia) {
                refInput.value = line.referencia;
            }
            const badge = row.querySelector('.aiscan-ref-badge');
            if (badge) {
                badge.innerHTML = buildProductMatchBadge(
                    line.referencia || '',
                    line.referencia_source || ''
                );
            }
        });
    }

    /**
     * Issue #76: un solo combobox con búsqueda, selección inmediata y limpieza.
     * ↑/↓ resaltan, Enter selecciona, Esc cierra, el icono de búsqueda está a la derecha.
     */
    function bindDefaultProductPicker() {
        const {input, clearBtn, searchBtn, resultsDiv, statusEl} = getDefaultProductPickerEls();
        if (!input || !searchBtn || !resultsDiv || input.dataset.bound === '1') {
            return;
        }
        input.dataset.bound = '1';

        let timer = null;
        let activeIndex = -1;
        const search = createLatestSearch('search-products');
        const resultsId = resultsDiv.id;

        const setStatus = (message, kind) => {
            if (!statusEl) {
                return;
            }
            statusEl.textContent = message || '';
            statusEl.className = 'small mt-1'
                + (kind === 'success' ? ' text-success' : '')
                + (kind === 'danger' ? ' text-danger' : '')
                + (kind === 'muted' || !kind ? ' text-muted' : '');
        };

        const closeResults = () => {
            closeAutocomplete(resultsDiv, input);
            activeIndex = -1;
        };

        const updateHighlight = () => highlightAutocompleteOption(resultsDiv, input, activeIndex);

        const renderResults = (items) => {
            if (!items.length) {
                resultsDiv.innerHTML = autocompleteNoResultsHtml();
                resultsDiv.classList.remove('d-none');
                input.setAttribute('aria-expanded', 'true');
                activeIndex = -1;
                return;
            }

            resultsDiv.innerHTML = buildProductOptionsHtml(items, resultsId);
            resultsDiv.classList.remove('d-none');
            input.setAttribute('aria-expanded', 'true');
            activeIndex = 0;
            updateHighlight();
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

        const selectProduct = async (referencia, description) => {
            const ref = String(referencia || '').trim();
            const desc = String(description || '').trim();
            if (!ref) {
                return;
            }

            closeResults();
            setDefaultProductPickerValue(ref, desc, input.dataset.codproveedor || '');
            setStatus('', 'muted');

            const ok = await saveDefaultProduct(ref, desc);
            if (ok) {
                setStatus(trans('aiscan-saved'), 'success');
            }
        };

        input.addEventListener('input', () => {
            // Al editar se deja el valor committed intacto para poder restaurarlo (Esc).
            input.dataset.referencia = '';
            input.dataset.description = '';
            if (clearBtn) {
                const hasCommitted = !!input.dataset.committedRef;
                clearBtn.classList.toggle('d-none', !hasCommitted);
                clearBtn.disabled = !hasCommitted;
            }
            clearTimeout(timer);
            timer = setTimeout(doSearch, 300);
        });

        input.addEventListener('focus', () => {
            // Si hay valor fijado, seleccionar todo para facilitar un nuevo criterio de búsqueda.
            if (input.dataset.committedRef) {
                input.select();
            }
        });

        input.addEventListener('keydown', e => {
            const options = Array.from(resultsDiv.querySelectorAll(AUTOCOMPLETE_OPTION_SELECTOR));
            if (e.key === 'ArrowDown') {
                if (!options.length) {
                    doSearch();
                    return;
                }
                e.preventDefault();
                activeIndex = Math.min(activeIndex + 1, options.length - 1);
                updateHighlight();
                return;
            }
            if (e.key === 'ArrowUp') {
                if (!options.length) {
                    return;
                }
                e.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                updateHighlight();
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                if (options[activeIndex]) {
                    selectProduct(options[activeIndex].dataset.ref, options[activeIndex].dataset.desc);
                } else {
                    doSearch();
                }
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                closeResults();
                // Restaurar la selección persistida si se canceló la búsqueda.
                if (input.dataset.committedRef) {
                    setDefaultProductPickerValue(
                        input.dataset.committedRef,
                        input.dataset.committedDesc || '',
                        input.dataset.codproveedor || ''
                    );
                }
            }
        });

        searchBtn.addEventListener('click', () => {
            input.focus();
            doSearch();
        });

        resultsDiv.addEventListener('click', e => {
            const item = e.target.closest(AUTOCOMPLETE_OPTION_SELECTOR);
            if (!item) {
                return;
            }
            selectProduct(item.dataset.ref, item.dataset.desc);
        });

        clearBtn?.addEventListener('click', async () => {
            closeResults();
            const ok = await clearDefaultProduct();
            if (ok) {
                setDefaultProductPickerValue('', '', input.dataset.codproveedor || '');
                setStatus(trans('aiscan-default-product-cleared'), 'success');
                input.focus();
            }
        });
    }

    function resolveDefaultProductSupplierCode() {
        const {input} = getDefaultProductPickerEls();
        const doc = currentDoc();
        return input?.dataset.codproveedor
            || doc?.extractedData?.supplier?.matched_supplier_id
            || '';
    }

    async function saveDefaultProduct(referencia, description) {
        const {input, statusEl} = getDefaultProductPickerEls();
        const doc = currentDoc();
        const codproveedor = resolveDefaultProductSupplierCode();
        const ref = String(referencia || '').trim();
        const desc = String(description || '').trim();

        if (!codproveedor || !ref) {
            if (statusEl) {
                statusEl.textContent = trans('aiscan-supplier-not-found-on-save') || 'Proveedor no resuelto';
                statusEl.className = 'small text-danger mt-1';
            }
            return false;
        }

        if (input) {
            input.dataset.codproveedor = codproveedor;
        }

        try {
            const response = await apiFetch('AiScanInvoice?action=set-supplier-default-product', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    codproveedor,
                    referencia: ref,
                    description: desc,
                }),
            });
            const data = await readJsonResponse(response);
            if (statusEl) {
                statusEl.textContent = data.success ? trans('aiscan-saved') : (data.error || 'Error');
                statusEl.className = data.success ? 'small text-success mt-1' : 'small text-danger mt-1';
            }
            // Issue #69: al guardar el pin, aplicarlo ya a las líneas del review.
            if (data.success && doc?.extractedData) {
                doc.extractedData._product_suggestion = {
                    referencia: ref,
                    description: desc,
                    source: 'pinned',
                };
                applyPinnedProductToLines(doc, ref);
                refreshLineProductBadges(doc);
            }
            return !!data.success;
        } catch (e) {
            if (statusEl) {
                statusEl.textContent = 'Error';
                statusEl.className = 'small text-danger mt-1';
            }
            return false;
        }
    }

    async function clearDefaultProduct() {
        const {statusEl} = getDefaultProductPickerEls();
        const doc = currentDoc();
        const codproveedor = resolveDefaultProductSupplierCode();

        if (!codproveedor) {
            if (statusEl) {
                statusEl.textContent = trans('aiscan-supplier-not-found-on-save') || 'Proveedor no resuelto';
                statusEl.className = 'small text-danger mt-1';
            }
            return false;
        }

        try {
            const response = await apiFetch('AiScanInvoice?action=set-supplier-default-product', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    codproveedor,
                    referencia: '',
                    clear: true,
                }),
            });
            const data = await readJsonResponse(response);
            if (statusEl) {
                statusEl.textContent = data.success
                    ? trans('aiscan-default-product-cleared')
                    : (data.error || 'Error');
                statusEl.className = data.success ? 'small text-success mt-1' : 'small text-danger mt-1';
            }
            if (data.success && doc?.extractedData) {
                if (doc.extractedData._product_suggestion?.source === 'pinned') {
                    delete doc.extractedData._product_suggestion;
                }
            }
            return !!data.success;
        } catch (e) {
            if (statusEl) {
                statusEl.textContent = 'Error';
                statusEl.className = 'small text-danger mt-1';
            }
            return false;
        }
    }

