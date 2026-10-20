    // ── Step 3: Import ─────────────────────────────────────────────────

    function bindImportStep() {
        document.getElementById('aiscan-back-to-review')?.addEventListener('click', () => {
            showStep('review');
            renderCurrentDocument();
        });
        const updateStockCheckbox = document.getElementById('aiscan-update-stock-purchase-data');
        if (updateStockCheckbox) {
            updateStockCheckbox.checked = state.updateStockPurchaseData;
            updateStockCheckbox.addEventListener('change', () => {
                state.updateStockPurchaseData = updateStockCheckbox.checked;
                buildImportSummary();
            });
        }
        document.getElementById('aiscan-import-all-btn')?.addEventListener('click', executeImport);
    }

    /**
     * Only the invoices marked ready when proceeding go to the import step;
     * the rest stay in review and are neither listed nor sent (#119).
     */
    function openImportStep() {
        state.documents.forEach(doc => {
            doc.inImport = doc.status === STATUS.READY;
        });
        showStep('import');
        buildImportSummary();
    }

    function importDocs() {
        return state.documents.filter(doc => doc.inImport);
    }

    function buildImportSummary() {
        const tbody = document.getElementById('aiscan-import-body');
        if (!tbody) {
            return;
        }

        tbody.innerHTML = state.documents.map((doc, i) => ({doc, i})).filter(({doc}) => doc.inImport).map(({doc, i}, row) => {
            const invoice = doc.extractedData?.invoice || {};
            const supplier = doc.extractedData?.supplier || {};
            const info = STATUS_LABELS[doc.status] || STATUS_LABELS.pending;
            const warnings = Array.isArray(doc.importWarnings) ? doc.importWarnings : [];
            const warningBadge = warnings.length > 0
                ? ` <span class="badge text-bg-warning aiscan-warning-badge" role="button" data-warning-index="${i}" title="${escapeAttr(trans('aiscan-import-warnings'))}" style="cursor:pointer"><i class="fa-solid fa-triangle-exclamation me-1"></i>${warnings.length}</span>`
                : '';
            return `
                <tr>
                    <td>${row + 1}</td>
                    <td>${escapeHtml(doc.originalName)}</td>
                    <td>${escapeHtml(supplier.name || '-')}</td>
                    <td>${escapeHtml(invoice.number || '-')}</td>
                    <td>${escapeHtml(invoice.issue_date || '-')}</td>
                    <td>${escapeHtml(invoice.total != null ? fmtNumber(parseFloat(invoice.total) || 0) : '-')}</td>
                    <td>${doc.status === STATUS.FAILED && doc.error
                        ? `<span class="badge ${info.cls}" role="button" data-bs-toggle="tooltip" data-bs-placement="top" title="${escapeAttr(doc.error)}" data-error="${escapeAttr(doc.error)}" style="cursor:pointer"><i class="fa-solid ${info.icon} me-1"></i>${escapeHtml(trans('aiscan-status-' + doc.status))}</span>`
                        : `<span class="badge ${info.cls}"><i class="fa-solid ${info.icon} me-1"></i>${escapeHtml(trans('aiscan-status-' + doc.status))}</span>`
                    }${warningBadge}</td>
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

        tbody.querySelectorAll('[data-error]').forEach(el => {
            new bootstrap.Tooltip(el);
            el.addEventListener('click', () => {
                showErrorModal(el.dataset.error);
            });
        });

        tbody.querySelectorAll('[data-warning-index]').forEach(el => {
            el.addEventListener('click', () => {
                const doc = state.documents[parseInt(el.dataset.warningIndex, 10)];
                showWarningModal(doc?.importWarnings || []);
            });
        });

        const countEl = document.getElementById('aiscan-import-count');
        if (countEl) {
            const docs = importDocs();
            const importable = docs.filter(d => d.status === STATUS.READY).length;
            countEl.textContent = trans('aiscan-import-count', {'%count%': String(importable), '%total%': String(docs.length)});
        }

        const optionSummaryEl = document.getElementById('aiscan-import-option-summary');
        if (optionSummaryEl) {
            optionSummaryEl.textContent = trans('aiscan-import-option-stock-summary', {
                '%status%': state.updateStockPurchaseData
                    ? trans('aiscan-stock-update-enabled')
                    : trans('aiscan-stock-update-disabled'),
            });
        }
    }

    function showErrorModal(errorText) {
        let modal = document.getElementById('aiscan-error-modal');
        if (!modal) {
            document.body.insertAdjacentHTML('beforeend', `
                <div class="modal fade" id="aiscan-error-modal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header py-2">
                                <h6 class="modal-title"><i class="fa-solid fa-circle-exclamation text-danger me-1"></i>Error</h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body" id="aiscan-error-modal-body"></div>
                            <div class="modal-footer py-1">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">${escapeHtml(trans('close'))}</button>
                            </div>
                        </div>
                    </div>
                </div>`);
            modal = document.getElementById('aiscan-error-modal');
        }
        document.getElementById('aiscan-error-modal-body').textContent = errorText;
        new bootstrap.Modal(modal).show();
    }

    function showWarningModal(warnings) {
        if (!Array.isArray(warnings) || warnings.length === 0) {
            return;
        }

        let modal = document.getElementById('aiscan-warning-modal');
        if (!modal) {
            document.body.insertAdjacentHTML('beforeend', `
                <div class="modal fade" id="aiscan-warning-modal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header py-2">
                                <h6 class="modal-title"><i class="fa-solid fa-triangle-exclamation text-warning me-1"></i>${escapeHtml(trans('aiscan-import-warnings'))}</h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body" id="aiscan-warning-modal-body"></div>
                            <div class="modal-footer py-1">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">${escapeHtml(trans('close'))}</button>
                            </div>
                        </div>
                    </div>
                </div>`);
            modal = document.getElementById('aiscan-warning-modal');
        }

        document.getElementById('aiscan-warning-modal-body').innerHTML = `<ul class="mb-0">${warnings.map(item => `<li>${escapeHtml(item)}</li>`).join('')}</ul>`;
        new bootstrap.Modal(modal).show();
    }

    async function executeImport() {
        const importBtn = document.getElementById('aiscan-import-all-btn');
        importBtn.disabled = true;
        importBtn.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-1"></i>${escapeHtml(trans('aiscan-importing'))}`;

        const docs = importDocs();

        // Last-chance client gate (#78): demote READY docs missing vital fields.
        docs.forEach(doc => {
            revokeReadyIfBlocked(doc, doc.extractedData);
        });

        const documents = docs.map(doc => {
            const extracted = doc.extractedData ? JSON.parse(JSON.stringify(doc.extractedData)) : null;
            if (extracted && extracted.invoice && !extracted.invoice.codpago) {
                extracted.invoice.codpago = state.codpago;
            }
            if (extracted) {
                extracted.supplier = applyPartyTypeToSupplier(
                    extracted.supplier || {},
                    resolveDocPartyType(doc)
                );
            }
            // Only send READY (or discarded/failed) statuses; demoted docs stay needs_review.
            return {
                status: doc.status,
                extracted_data: extracted,
                tmp_file: doc.tmpFile,
                mime_type: doc.mimeType,
                original_name: doc.originalName,
                import_mode: doc._importMode || state.importMode,
                update_stock_purchase_data: state.updateStockPurchaseData,
            };
        });

        try {
            const response = await apiFetch('AiScanInvoice?action=import-batch', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    documents,
                    import_mode: state.importMode,
                    provider: state.defaultProvider,
                    update_stock_purchase_data: state.updateStockPurchaseData,
                }),
            });
            const data = await readJsonResponse(response);

            if (!data.success) {
                throw new Error(data.error || 'Import failed');
            }

            (data.results || []).forEach(result => {
                const doc = docs[result.index];
                if (!doc) {
                    return;
                }
                if (result.status === 'imported') {
                    doc.status = STATUS.IMPORTED;
                    doc.invoiceId = result.invoice_id;
                    doc.importWarnings = result.warnings || [];
                } else if (result.status === 'error') {
                    doc.status = STATUS.FAILED;
                    doc.error = result.error;
                    doc.importWarnings = result.warnings || [];
                } else if (result.status === 'skipped') {
                    doc.status = STATUS.DISCARDED;
                    doc.importWarnings = result.warnings || [];
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

