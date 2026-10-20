    function bindSupplierSearch() {
        const searchInput = document.getElementById('aiscan-supplier-search');
        const searchBtn = document.getElementById('aiscan-supplier-search-btn');
        const resultsDiv = document.getElementById('aiscan-supplier-results');
        if (!searchInput || !searchBtn || !resultsDiv) {
            return;
        }

        let timer = null;
        const search = createLatestSearch('search-suppliers');
        const doSearch = () => {
            const query = searchInput.value.trim();
            if (query.length < MIN_AUTOCOMPLETE_QUERY_LENGTH) {
                resultsDiv.innerHTML = '';
                return;
            }
            search(query)
                .then(items => {
                    if (items === null) {
                        return;
                    }
                    if (items.length === 0) {
                        resultsDiv.innerHTML = autocompleteNoResultsHtml();
                        return;
                    }
                    resultsDiv.innerHTML = items.map(s => {
                        const itemParty = normalizePartyType(s.party_type || (s.is_creditor ? PARTY_CREDITOR : PARTY_SUPPLIER));
                        const partyLabel = itemParty === PARTY_CREDITOR
                            ? trans('aiscan-party-type-creditor')
                            : trans('aiscan-party-type-supplier');
                        return `<button type="button" class="list-group-item list-group-item-action small py-1"
                            data-id="${escapeAttr(s.id)}"
                            data-name="${escapeAttr(s.name)}"
                            data-taxid="${escapeAttr(s.tax_id || '')}"
                            data-party-type="${escapeAttr(itemParty)}">
                            <strong>${escapeHtml(s.name)}</strong>
                            <span class="text-muted">${escapeHtml(s.tax_id || '')}</span>
                            <span class="badge text-bg-light ms-1">${escapeHtml(partyLabel)}</span>
                        </button>`;
                    }).join('');
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
                applySelectedSupplier(doc.extractedData.supplier, {
                    id: item.dataset.id,
                    name: item.dataset.name,
                    tax_id: item.dataset.taxid,
                }, 'selected');
                // Keep the import party type selected by the user (page 1 / review),
                // not the historical type of the matched record. On import, that
                // choice updates Proveedor.acreedor when it differs.
                applyPartyTypeToSupplier(doc.extractedData.supplier, resolveDocPartyType(doc));
                // Issue #71: aprender alias solo en elección explícita del usuario.
                rememberSupplierAlias(doc.extractedData.supplier, item.dataset.id);
            }
            // Issue #53: al elegir proveedor a mano, sugerir producto habitual
            applySupplierProductSuggestion(doc, item.dataset.id).then(() => renderReviewPanel(doc));
        });

        const partyTypeSelect = document.getElementById('supplier_party_type');
        if (partyTypeSelect) {
            partyTypeSelect.addEventListener('change', () => {
                const doc = currentDoc();
                if (!doc) {
                    return;
                }
                const partyType = normalizePartyType(partyTypeSelect.value);
                doc._partyType = partyType;
                if (doc.extractedData) {
                    doc.extractedData.supplier = applyPartyTypeToSupplier(
                        doc.extractedData.supplier || {},
                        partyType
                    );
                }
            });
        }

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
                    applySelectedSupplier(doc.extractedData.supplier, {
                        id: opt.value,
                        name: nameMatch ? nameMatch[1] : text,
                        tax_id: nameMatch ? nameMatch[2] : '',
                    }, 'selected');
                    // Issue #71: desambiguación = elección explícita
                    rememberSupplierAlias(doc.extractedData.supplier, opt.value);
                }
                applySupplierProductSuggestion(doc, opt.value).then(() => renderReviewPanel(doc));
            });
        }

        // Bind inline create supplier button
        const createBtn = document.getElementById('aiscan-create-supplier-btn');
        if (createBtn) {
            createBtn.addEventListener('click', createSupplierInline);
        }
    }

    /**
     * Issue #71: persiste el alias (fingerprint → codproveedor) solo cuando el
     * usuario elige o crea el proveedor a mano. Nunca se llama tras un auto-match.
     */
    async function rememberSupplierAlias(supplier, codproveedor) {
        if (!codproveedor || !supplier) {
            return;
        }
        try {
            await apiFetch('AiScanInvoice?action=remember-supplier-alias', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    codproveedor,
                    tax_id: supplier.tax_id || '',
                    name: supplier.name || '',
                    email: supplier.email || '',
                    iban: supplier.iban || '',
                }),
            });
        } catch (e) {
            // silent — el alias es best-effort
        }
    }

    /**
     * Issue #53: rellena líneas sin producto con el producto habitual del proveedor.
     */
    async function applySupplierProductSuggestion(doc, codproveedor) {
        if (!doc?.extractedData || !codproveedor) {
            return;
        }
        try {
            const response = await apiFetch(
                'AiScanInvoice?' + new URLSearchParams({
                    action: 'suggest-supplier-products',
                    codproveedor,
                })
            );
            const data = await readJsonResponse(response);
            if (!data.found || !data.referencia) {
                return;
            }
            doc.extractedData._product_suggestion = data;
            if (!Array.isArray(doc.extractedData.lines)) {
                return;
            }
            const source = data.source || 'history';
            doc.extractedData.lines = doc.extractedData.lines.map(line => {
                const ref = String(line.referencia || line.sku || '').trim();
                if (ref !== '' && line.referencia_source !== 'history' && line.referencia_source !== 'pinned') {
                    return line;
                }
                return {
                    ...line,
                    referencia: data.referencia,
                    referencia_source: source === 'pinned' ? 'pinned' : 'history',
                };
            });
        } catch (e) {
            // silent
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
            const doc = currentDoc();
            const partyType = resolveDocPartyType(doc);
            const response = await apiFetch('AiScanInvoice?action=create-supplier', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    name,
                    tax_id: taxIdEl?.value?.trim() || '',
                    email: emailEl?.value?.trim() || '',
                    phone: phoneEl?.value?.trim() || '',
                    party_type: partyType,
                    is_creditor: isCreditorPartyType(partyType),
                }),
            });
            const data = await readJsonResponse(response);

            // Issue #134: el CIF ya existe; se enlaza ese proveedor en vez de dejar
            // el documento sin proveedor y obligar a buscarlo a mano.
            if (data.existing_supplier && doc?.extractedData?.supplier) {
                const existing = data.existing_supplier;
                applySelectedSupplier(doc.extractedData.supplier, existing, 'selected');
                applyPartyTypeToSupplier(doc.extractedData.supplier, partyType);
                rememberSupplierAlias(doc.extractedData.supplier, existing.id);
                await applySupplierProductSuggestion(doc, existing.id);
                renderReviewPanel(doc);
                const linkedStatusEl = document.getElementById('aiscan-create-supplier-status');
                if (linkedStatusEl) {
                    linkedStatusEl.className = 'small text-warning';
                    linkedStatusEl.textContent = data.error || '';
                }
                return;
            }

            if (data.error) {
                throw new Error(data.error);
            }

            // Update current document's supplier data
            if (doc?.extractedData?.supplier) {
                applySelectedSupplier(doc.extractedData.supplier, data.supplier, 'created');
                applyPartyTypeToSupplier(
                    doc.extractedData.supplier,
                    data.supplier.party_type || partyType
                );
                doc._partyType = normalizePartyType(data.supplier.party_type || partyType);
                // Issue #71: crear proveedor también es elección explícita
                rememberSupplierAlias(doc.extractedData.supplier, data.supplier.id);
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

