    /**
     * Open the review panel for every uploaded document with an empty editable
     * payload (no AI call). Used when the user chooses manual entry or when
     * no provider/API key is configured.
     */
    function openAllAsManualEntry(message) {
        const msg = message || trans('aiscan-scan-failed-manual-entry');
        state.documents.forEach(doc => {
            if (!doc.tmpFile || doc.status === STATUS.FAILED) {
                return;
            }
            applyManualEntryFallback(doc, {
                message: msg,
                scanFailureCode: 'manual_entry',
            });
        });
        if (document.getElementById('aiscan-review') || document.getElementById('aiscan-preview-area')) {
            renderCurrentDocument();
        }
    }

    /**
     * Empty extraction shell matching the server-side schema so the review
     * form and import pipeline work without a prior successful scan.
     */
    function buildEmptyExtractedData(message) {
        const warning = message || '';
        return {
            document_type: null,
            supplier: {
                name: null,
                tax_id: null,
                email: null,
                phone: null,
                website: null,
                address: null,
            },
            customer: {
                name: null,
                tax_id: null,
                address: null,
            },
            invoice: {
                number: null,
                issue_date: null,
                due_date: null,
                currency: null,
                subtotal: null,
                tax_amount: null,
                withholding_amount: null,
                total: null,
                summary: null,
                payment_terms: null,
            },
            taxes: [],
            lines: [],
            confidence: {
                supplier_name: 0,
                supplier_tax_id: 0,
                invoice_number: 0,
                issue_date: 0,
                total: 0,
                lines: 0,
            },
            warnings: warning ? [warning] : [],
            _validation_errors: [],
            _provider: null,
            _scan_failed: true,
        };
    }

    /**
     * Apply controlled scan-failure / manual-entry payload: keep the document
     * reviewable and importable instead of STATUS.FAILED.
     */
    function applyManualEntryFallback(doc, options) {
        const opts = options || {};
        const message = opts.message || trans('aiscan-scan-failed-manual-entry');
        const data = opts.data && typeof opts.data === 'object'
            ? opts.data
            : buildEmptyExtractedData(message);

        if (!Array.isArray(data.warnings)) {
            data.warnings = [];
        }
        if (message && !data.warnings.includes(message)) {
            data.warnings.unshift(message);
        }
        data._scan_failed = true;

        doc.extractedData = data;
        doc.scanFailed = true;
        doc.scanFailedMessage = message;
        doc.scanFailureCode = opts.scanFailureCode || data._scan_failure_code || 'unknown';
        doc.error = null;
        finalizeAnalyzedDoc(doc);
        // Always require review when scan did not populate fields.
        doc.status = STATUS.NEEDS_REVIEW;
    }

    /**
     * Handle analyze API response: success, multi-invoice, or scan_failed.
     * Returns true when the document was finalized (including manual fallback).
     */
    function applyAnalyzeResponse(doc, data) {
        if (data && data.scan_failed) {
            applyManualEntryFallback(doc, {
                message: data.message || trans('aiscan-scan-failed-manual-entry'),
                data: data.data,
                scanFailureCode: data.scan_failure_code || 'unknown',
            });
            return true;
        }

        if (data && data.error) {
            throw new Error(data.error);
        }

        if (data && data._multi_invoice && Array.isArray(data.invoices) && data.invoices.length > 1) {
            handleMultiInvoiceResponse(doc, data.invoices);
            return true;
        }

        if (!data || !data.data) {
            throw new Error(trans('aiscan-scan-failed-invalid-response'));
        }

        doc.extractedData = data.data;
        doc.scanFailed = false;
        doc.scanFailedMessage = null;
        doc.scanFailureCode = null;
        doc.error = null;
        finalizeAnalyzedDoc(doc);
        return true;
    }

    async function analyzeAllPending() {
        const choice = selectedModelChoice();
        const concurrency = state.maxParallelRequests;
        const queue = state.documents.filter(d => d.tmpFile && d.status !== STATUS.FAILED);

        let running = 0;
        let nextIdx = 0;

        function refreshUI(changedDoc) {
            renderSidebar();
            const cur = currentDoc();
            const openModal = document.querySelector('.modal.show');
            const action = resolveReviewRefresh(changedDoc, cur, !!openModal);
            if (action === 'defer') {
                // Issue #135: repintar con un modal abierto lo destruye y deja el
                // fondo bloqueando la página; se espera a que se cierre, con un
                // único aviso aunque terminen varios análisis mientras tanto.
                if (!openModal.dataset.aiscanRefreshPending) {
                    openModal.dataset.aiscanRefreshPending = '1';
                    openModal.addEventListener('hidden.bs.modal', () => {
                        delete openModal.dataset.aiscanRefreshPending;
                        refreshUI(currentDoc());
                    }, {once: true});
                }
            } else if (action === 'render') {
                renderPreview(cur);
                renderReviewPanel(cur);
            }
        }

        async function analyzeOne(doc) {
            doc.status = STATUS.ANALYZING;
            doc.analyzingChoice = choice;
            refreshUI(doc);

            try {
                const params = buildAnalyzeParams(doc, {
                    import_mode: state.importMode,
                    use_history: state.useHistory ? '1' : '0',
                    supplier_id: '',
                    provider: choice.provider,
                    model: choice.model,
                });

                const response = await apiFetch('AiScanInvoice?' + params.toString());
                let data;
                try {
                    data = await response.json();
                } catch (parseError) {
                    applyManualEntryFallback(doc, {
                        message: trans('aiscan-scan-failed-invalid-response'),
                        scanFailureCode: 'invalid_json',
                    });
                    refreshUI(doc);
                    return;
                }

                // Controlled AI failure from backend (#67)
                if (data && data.scan_failed) {
                    applyManualEntryFallback(doc, {
                        message: data.message || trans('aiscan-scan-failed-manual-entry'),
                        data: data.data,
                        scanFailureCode: data.scan_failure_code || 'unknown',
                    });
                    refreshUI(doc);
                    return;
                }

                if (data && data.error) {
                    // Unexpected hard error without scan_failed flag: still open manual panel.
                    applyManualEntryFallback(doc, {
                        message: data.error || trans('aiscan-scan-failed-manual-entry'),
                        scanFailureCode: 'unknown',
                    });
                    refreshUI(doc);
                    return;
                }

                // Multi-invoice: the AI found several invoices in one document
                if (data._multi_invoice && Array.isArray(data.invoices) && data.invoices.length > 1) {
                    handleMultiInvoiceResponse(doc, data.invoices);
                    refreshUI(doc);
                    return;
                }

                if (!data || !data.data) {
                    applyManualEntryFallback(doc, {
                        message: trans('aiscan-scan-failed-invalid-response'),
                        scanFailureCode: 'invalid_json',
                    });
                    refreshUI(doc);
                    return;
                }

                doc.extractedData = data.data;
                doc.scanFailed = false;
                doc.scanFailedMessage = null;
                doc.scanFailureCode = null;
                doc.error = null;

                if (state.useHistory && doc.extractedData?.supplier?.matched_supplier_id && !doc._historyAnalyzed) {
                    doc._historyAnalyzed = true;
                    const reParams = buildAnalyzeParams(doc, {
                        import_mode: state.importMode,
                        use_history: '1',
                        supplier_id: doc.extractedData.supplier.matched_supplier_id,
                        provider: choice.provider,
                        model: choice.model,
                    });
                    const reResponse = await apiFetch('AiScanInvoice?' + reParams.toString());
                    // Issue #135: si el reanálisis con historial falla, se conserva la primera extracción.
                    const reData = reResponse.ok ? await reResponse.json().catch(() => null) : null;
                    if (reData && reData.scan_failed) {
                        // Keep first extraction; history re-scan failure is non-blocking.
                    } else if (reData && reData.success && reData.data) {
                        doc.extractedData = reData.data;
                        doc.scanFailed = false;
                    }
                }

                finalizeAnalyzedDoc(doc);
            } catch (error) {
                // Network/transport errors: open empty editable panel instead of FAILED.
                applyManualEntryFallback(doc, {
                    message: error && error.message
                        ? error.message
                        : trans('aiscan-scan-failed-network'),
                    scanFailureCode: 'network_error',
                });
            }

            refreshUI(doc);
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

    /**
     * Apply post-analysis checks to a single analyzed document.
     */
    function finalizeAnalyzedDoc(doc) {
        if (!doc.extractedData) {
            doc.extractedData = {};
        }
        doc.extractedData.supplier = applyPartyTypeToSupplier(
            doc.extractedData.supplier || {},
            doc._partyType || state.partyType
        );
        doc._partyType = resolveDocPartyType(doc);
        // Issue #56: confianza 0 en campos vacíos (p. ej. CIF no detectado)
        sanitizeExtractedConfidence(doc.extractedData);

        const warnings = filterOutDynamicWarnings(doc.extractedData);
        let needsReview = warnings.length > 0;

        // Server-side duplicate: invoice already exists in FS
        if (doc.extractedData?._duplicate) {
            needsReview = true;
        }

        // In-batch duplicate: same invoice already analyzed in this batch
        const batchDup = checkInBatchDuplicate(doc);
        if (batchDup && !warnings.includes(batchDup)) {
            warnings.push(batchDup);
            needsReview = true;
        }

        // CIF/NIF o nombre de proveedor vacío (sin match): forzar revisión (#56 / #78)
        const matchedId = String(doc.extractedData?.supplier?.matched_supplier_id ?? '').trim();
        if (!matchedId) {
            const taxId = String(doc.extractedData?.supplier?.tax_id ?? '').trim();
            if (!taxId) {
                needsReview = true;
                const missingTaxMsg = trans('aiscan-missing-supplier-tax-id');
                if (missingTaxMsg && !warnings.includes(missingTaxMsg)) {
                    warnings.push(missingTaxMsg);
                }
            }
            const supplierName = String(doc.extractedData?.supplier?.name ?? '').trim();
            if (!supplierName) {
                needsReview = true;
                const missingNameMsg = trans('aiscan-supplier-name-required');
                if (missingNameMsg && !warnings.includes(missingNameMsg)) {
                    warnings.push(missingNameMsg);
                }
            }
        }

        // In total mode, generate synthetic lines for validation
        if (state.importMode === 'total' && !doc.extractedData.lines?.length) {
            doc.extractedData.lines = buildTotalLines(doc.extractedData);
        }

        // Check if calculated total matches AI total
        const totalMismatch = checkTotalMismatch(doc.extractedData);
        if (totalMismatch) {
            doc.extractedData._total_mismatch_warning = totalMismatch.message;
            if (!totalMismatch.isRounding) {
                needsReview = true;
            }
        } else {
            delete doc.extractedData._total_mismatch_warning;
        }

        doc.extractedData._validation_errors = warnings;
        doc.status = needsReview ? STATUS.NEEDS_REVIEW : STATUS.ANALYZED;
    }

    function sanitizeExtractedConfidence(data) {
        if (!data || typeof data !== 'object') {
            return;
        }
        data.confidence = data.confidence && typeof data.confidence === 'object'
            ? data.confidence
            : {};
        const supplier = data.supplier || {};
        const invoice = data.invoice || {};
        const pairs = [
            ['supplier_name', supplier.name],
            ['supplier_tax_id', supplier.tax_id],
            ['invoice_number', invoice.number],
            ['issue_date', invoice.issue_date],
            ['total', invoice.total],
        ];
        pairs.forEach(([key, value]) => {
            const resolved = resolveFieldConfidence(value, data.confidence[key]);
            if (resolved !== null) {
                data.confidence[key] = resolved;
            } else if (value == null || (typeof value === 'string' && value.trim() === '')) {
                data.confidence[key] = 0;
            }
        });
    }

    /**
     * Handle a multi-invoice response from one uploaded document.
     *
     * The original document entry is updated with the first invoice, and
     * additional entries are inserted into state.documents for each
     * subsequent invoice found.
     */
    function handleMultiInvoiceResponse(originalDoc, invoices) {
        const docIndex = state.documents.indexOf(originalDoc);

        // Build entries for each detected invoice
        const newDocs = invoices.map((invoiceData, i) => {
            const pageRange = invoiceData.page_range || null;
            const invoiceNum = invoiceData.invoice?.number || '';
            const suffix = invoices.length > 1
                ? ` [${i + 1}/${invoices.length}${pageRange ? ' p.' + pageRange : ''}]`
                : '';

            if (i === 0) {
                // Reuse the original document entry for the first invoice
                originalDoc.extractedData = invoiceData;
                originalDoc.originalName = originalDoc.originalName + suffix;
                originalDoc._multiInvoiceSource = true;
                finalizeAnalyzedDoc(originalDoc);
                return null; // sentinel, already updated in-place
            }

            // Create new document entries for additional invoices
            return {
                index: docIndex + i,
                file: originalDoc.file,
                originalName: originalDoc.originalName.replace(/ \[1\/.*$/, '') + suffix,
                mimeType: originalDoc.mimeType,
                size: originalDoc.size,
                objectUrl: originalDoc.objectUrl,
                tmpFile: originalDoc.tmpFile,
                status: STATUS.PENDING,
                extractedData: invoiceData,
                error: null,
                reviewDecision: null,
                _multiInvoiceSource: true,
            };
        });

        // Insert additional documents right after the original
        const toInsert = newDocs.filter(d => d !== null);
        if (toInsert.length > 0) {
            // Issue #135: refreshUI() ya no repinta documentos ajenos, así que el
            // documento abierto tiene que seguir siendo el mismo tras insertar.
            const openDoc = currentDoc();
            state.documents.splice(docIndex + 1, 0, ...toInsert);
            // Re-index all documents
            state.documents.forEach((d, i) => { d.index = i; });
            if (openDoc && state.documents.indexOf(openDoc) !== -1) {
                state.currentIndex = state.documents.indexOf(openDoc);
            }
        }

        // Finalize status for the newly inserted documents
        toInsert.forEach(doc => {
            finalizeAnalyzedDoc(doc);
        });
    }

