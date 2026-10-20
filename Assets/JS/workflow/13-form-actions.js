    function persistCurrentFormState() {
        const doc = currentDoc();
        if (!doc || !doc.extractedData) {
            return;
        }
        doc.extractedData = collectFormData(doc.extractedData);
    }

    function collectFormData(baseData) {
        const data = JSON.parse(JSON.stringify(baseData));

        // Only collect from DOM if the review form is rendered
        if (!document.getElementById('aiscan-review')?.children.length) {
            return data;
        }

        data.invoice = data.invoice || {};
        data.supplier = data.supplier || {};

        data.invoice.number = valOr('invoice_number', data.invoice.number);
        data.invoice.issue_date = valOr('invoice_issue_date', data.invoice.issue_date);
        data.invoice.due_date = valOr('invoice_due_date', data.invoice.due_date);
        data.invoice.currency = valOr('invoice_currency', data.invoice.currency);
        data.invoice.subtotal = parseLocaleNumber(val('invoice_subtotal')) || data.invoice.subtotal || 0;
        data.invoice.tax_amount = parseLocaleNumber(val('invoice_tax_amount')) || data.invoice.tax_amount || 0;
        data.invoice.total = parseLocaleNumber(val('invoice_total')) || data.invoice.total || 0;
        const withholdingEl = document.getElementById('invoice_withholding');
        if (withholdingEl) {
            data.invoice.withholding_amount = parseLocaleNumber(withholdingEl.value);
        }
        data.invoice.summary = valOr('invoice_summary', data.invoice.summary);
        const ptEl = document.getElementById('invoice_payment_terms');
        if (ptEl) {
            data.invoice.payment_terms = ptEl.value;
        }
        data.invoice.codpago = valOr('invoice_codpago', data.invoice.codpago || state.codpago);

        data.supplier.name = valOr('supplier_name', data.supplier.name);
        data.supplier.tax_id = valOr('supplier_tax_id', data.supplier.tax_id);
        data.supplier.email = valOr('supplier_email', data.supplier.email);
        data.supplier.phone = valOr('supplier_phone', data.supplier.phone);
        data.supplier.address = valOr('supplier_address', data.supplier.address);

        const partyTypeEl = document.getElementById('supplier_party_type');
        const partyType = normalizePartyType(partyTypeEl ? partyTypeEl.value : resolveDocPartyType({extractedData: data}));
        applyPartyTypeToSupplier(data.supplier, partyType);

        const selectedSupplier = document.getElementById('supplier_match_select');
        if (selectedSupplier) {
            data.supplier.matched_supplier_id = selectedSupplier.value;
            delete data.supplier.create_if_missing;
        }

        const lineRows = document.querySelectorAll('#aiscan-lines-body .aiscan-line-row');
        if (lineRows.length > 0) {
            data.lines = Array.from(lineRows).map(row => {
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

    function valOr(id, fallback) {
        const el = document.getElementById(id);
        return el ? el.value : (fallback || '');
    }

    function markCurrentReady() {
        persistCurrentFormState();
        const doc = currentDoc();
        if (!doc || !doc.extractedData) {
            return;
        }

        const blocking = getBlockingImportErrors(doc.extractedData);
        if (blocking.length > 0) {
            updateMarkReadyButton(doc.extractedData);
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

    async function reanalyzeDocs(docs, modeOverride, choice) {
        const importMode = modeOverride || state.importMode;

        for (const doc of docs) {
            const target = choice || modelChoiceForDoc(doc);
            doc.status = STATUS.ANALYZING;
            doc.analyzingChoice = target;
            doc.error = null;
            doc.reviewDecision = null;
            doc._importMode = importMode;
            renderSidebar();
            if (doc.index === state.currentIndex) {
                renderPreview(doc);
                renderReviewPanel(doc);
            }

            try {
                const params = buildAnalyzeParams(doc, {
                    import_mode: importMode,
                    use_history: state.useHistory ? '1' : '0',
                    supplier_id: doc.extractedData?.supplier?.matched_supplier_id || '',
                    provider: target.provider,
                    model: target.model,
                });

                const response = await apiFetch('AiScanInvoice?' + params.toString());
                const data = await readJsonResponse(response);
                applyAnalyzeResponse(doc, data);
            } catch (error) {
                applyManualEntryFallback(doc, {
                    message: error && error.message
                        ? error.message
                        : trans('aiscan-scan-failed-network'),
                    scanFailureCode: 'network_error',
                });
            }

            renderSidebar();
            if (doc.index === state.currentIndex) {
                renderPreview(doc);
                renderReviewPanel(doc);
            }
        }
    }

    async function reanalyzeCurrentDoc(choice) {
        const doc = currentDoc();
        if (!doc || !doc.tmpFile) {
            return;
        }

        const target = choice || modelChoiceForDoc(doc);
        doc.status = STATUS.ANALYZING;
        doc.analyzingChoice = target;
        doc.error = null;
        doc.reviewDecision = null;
        renderCurrentDocument();

        try {
            const params = buildAnalyzeParams(doc, {
                import_mode: state.importMode,
                use_history: state.useHistory ? '1' : '0',
                supplier_id: doc.extractedData?.supplier?.matched_supplier_id || '',
                provider: target.provider,
                model: target.model,
            });

            const response = await apiFetch('AiScanInvoice?' + params.toString());
            const data = await readJsonResponse(response);
            applyAnalyzeResponse(doc, data);
        } catch (error) {
            applyManualEntryFallback(doc, {
                message: error && error.message
                    ? error.message
                    : trans('aiscan-scan-failed-network'),
                scanFailureCode: 'network_error',
            });
        }

        renderCurrentDocument();
    }

    /**
     * Provider/model currently picked in the analysis selector (#89).
     */
    function selectedModelChoice() {
        const select = document.getElementById('aiscan-provider-select');
        const option = select && select.options ? select.options[select.selectedIndex] : null;
        if (option && option.value) {
            return {provider: option.value, model: option.dataset ? (option.dataset.model || '') : ''};
        }
        return {provider: state.defaultProvider, model: state.defaultModel || ''};
    }

    /**
     * Model a document was last analyzed with, so re-analyzing repeats it
     * instead of silently switching model (#89).
     */
    function modelChoiceForDoc(doc) {
        // While (re)analyzing, report the model in use, not the previous one (#106).
        if (doc && doc.status === STATUS.ANALYZING && doc.analyzingChoice) {
            return doc.analyzingChoice;
        }
        const provider = doc && doc.extractedData ? doc.extractedData._provider : null;
        if (provider) {
            return {provider, model: doc.extractedData._model || ''};
        }
        return selectedModelChoice();
    }

    function sameChoice(a, b) {
        return !!a && !!b && a.provider === b.provider && (a.model || '') === (b.model || '');
    }

    function choiceLabel(choice) {
        const model = choice.model || '';
        const known = (state.availableModels || []).find(c => c.provider === choice.provider && c.model === model);
        if (known) {
            return known.label;
        }
        return model ? `${choice.provider} \u2014 ${model}` : String(choice.provider || '');
    }

    function buildAnalyzeParams(doc, extra) {
        const params = new URLSearchParams({
            action: 'analyze',
            tmp_file: doc.tmpFile || '',
            mime_type: doc.mimeType || '',
            ...extra,
        });
        const provider = extra.provider || state.defaultProvider;
        if (provider === 'mock' && state.mockFixture) {
            params.set('mock_fixture', state.mockFixture);
        }
        return params;
    }

