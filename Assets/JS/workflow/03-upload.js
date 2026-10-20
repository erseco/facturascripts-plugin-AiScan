    // ── Step 1: Upload ─────────────────────────────────────────────────

    function bindUploadStep() {
        const dropZone = document.getElementById('aiscan-drop-zone');
        const fileInput = document.getElementById('aiscan-file-input');
        const uploadBtn = document.getElementById('aiscan-upload-btn');

        if (!dropZone || !fileInput) {
            return;
        }

        dropZone.addEventListener('click', () => {
            if (isUploadQueueLocked()) {
                return;
            }
            fileInput.click();
        });
        // Issue #135: la zona de subida también se abre con el teclado.
        dropZone.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') {
                return;
            }
            e.preventDefault();
            if (!isUploadQueueLocked()) {
                fileInput.click();
            }
        });
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (isUploadQueueLocked()) {
                return;
            }
            dropZone.classList.add('aiscan-drag-over');
        });
        dropZone.addEventListener('dragleave', (e) => {
            if (!dropZone.contains(e.relatedTarget)) {
                dropZone.classList.remove('aiscan-drag-over');
            }
        });
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('aiscan-drag-over');
            if (e.dataTransfer.files.length > 0) {
                onFilesSelected(e.dataTransfer.files);
            }
        });

        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                onFilesSelected(fileInput.files);
            }
        });

        uploadBtn.addEventListener('click', startUploadAndAnalyze);

        document.querySelectorAll('input[name="import_mode"]').forEach(radio => {
            radio.addEventListener('change', () => {
                state.importMode = radio.value;
                document.getElementById('aiscan-import-mode-error')?.classList.add('d-none');
            });
        });

        // Forma de pago solo en revisión (#57). Aquí solo se fija el valor por defecto.
        state.codpago = window.aiscanDefaultCodpago || '';

        const partyTypeSelect = document.getElementById('aiscan-party-type-select');
        if (partyTypeSelect) {
            state.partyType = normalizePartyType(partyTypeSelect.value);
            partyTypeSelect.addEventListener('change', () => {
                state.partyType = normalizePartyType(partyTypeSelect.value);
            });
        } else {
            state.partyType = PARTY_SUPPLIER;
        }

        const historyCheckbox = document.getElementById('use-history');
        if (historyCheckbox) {
            historyCheckbox.addEventListener('change', () => {
                state.useHistory = historyCheckbox.checked;
            });
        }

        bindMockDebugControls();

        dropZoneDefaultHtml = dropZone.innerHTML;

        const fileListBody = document.getElementById('aiscan-file-list-body');
        if (fileListBody) {
            fileListBody.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-aiscan-remove-file]');
                if (!btn) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                const index = parseInt(btn.getAttribute('data-aiscan-remove-file'), 10);
                if (Number.isInteger(index)) {
                    applyRemoveSelectedFile(index);
                    renderSelectedFiles();
                }
            });
        }
    }

    /**
     * Controles de modo depuración (proveedor mock + fixture).
     * Solo visibles cuando AiScanSettings.debug_mode está activo.
     */
    function bindMockDebugControls() {
        const panel = document.getElementById('aiscan-mock-panel');
        if (!panel || !state.debugMode) {
            return;
        }
        panel.classList.remove('d-none');

        const fixtureSelect = document.getElementById('aiscan-mock-fixture-select');
        const nextBtn = document.getElementById('aiscan-mock-next-fixture');
        const providerSelect = document.getElementById('aiscan-provider-select');

        if (fixtureSelect) {
            if (state.mockFixtures.length && !state.mockFixture) {
                state.mockFixture = state.mockFixtures[0];
            }
            fixtureSelect.innerHTML = state.mockFixtures.map(name =>
                `<option value="${escapeAttr(name)}"${name === state.mockFixture ? ' selected' : ''}>${escapeHtml(name)}</option>`
            ).join('');
            fixtureSelect.addEventListener('change', () => {
                state.mockFixture = fixtureSelect.value;
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                if (!state.mockFixtures.length) {
                    return;
                }
                const idx = state.mockFixtures.indexOf(state.mockFixture);
                const next = state.mockFixtures[(idx + 1) % state.mockFixtures.length];
                state.mockFixture = next;
                if (fixtureSelect) {
                    fixtureSelect.value = next;
                }
                // Prefer mock provider when cycling fixtures
                if (providerSelect) {
                    const hasMock = Array.from(providerSelect.options).some(o => o.value === 'mock');
                    if (hasMock) {
                        providerSelect.value = 'mock';
                        state.defaultProvider = 'mock';
                    }
                }
                const label = document.getElementById('aiscan-mock-current-label');
                if (label) {
                    label.textContent = next;
                }
            });
        }

        if (providerSelect) {
            providerSelect.addEventListener('change', () => {
                const mockBox = document.getElementById('aiscan-mock-fixture-box');
                if (mockBox) {
                    mockBox.classList.toggle('d-none', providerSelect.value !== 'mock');
                }
            });
            // Initial visibility
            const mockBox = document.getElementById('aiscan-mock-fixture-box');
            if (mockBox) {
                mockBox.classList.toggle('d-none', providerSelect.value !== 'mock');
            }
        }
    }

    function normalizePartyType(value) {
        const normalized = String(value || '').toLowerCase().trim();
        if (normalized === PARTY_CREDITOR || normalized === 'acreedor') {
            return PARTY_CREDITOR;
        }
        return PARTY_SUPPLIER;
    }

    function isCreditorPartyType(value) {
        return normalizePartyType(value) === PARTY_CREDITOR;
    }

    function applyPartyTypeToSupplier(supplier, partyType) {
        const target = supplier && typeof supplier === 'object' ? supplier : {};
        const normalized = normalizePartyType(partyType);
        target.party_type = normalized;
        target.is_creditor = normalized === PARTY_CREDITOR;
        return target;
    }

    function resolveDocPartyType(doc) {
        if (doc?.extractedData?.supplier?.party_type) {
            return normalizePartyType(doc.extractedData.supplier.party_type);
        }
        if (doc?.extractedData?.supplier && Object.prototype.hasOwnProperty.call(doc.extractedData.supplier, 'is_creditor')) {
            return doc.extractedData.supplier.is_creditor ? PARTY_CREDITOR : PARTY_SUPPLIER;
        }
        if (doc?._partyType) {
            return normalizePartyType(doc._partyType);
        }
        return normalizePartyType(state.partyType);
    }



    let dropZoneDefaultHtml = '';

    function isUploadQueueLocked() {
        return !!state.queueLocked;
    }

    function setUploadQueueLocked(locked) {
        state.queueLocked = !!locked;
    }

    function applySelectedFiles(fileList) {
        if (isUploadQueueLocked()) {
            return state.documents;
        }
        const incoming = Array.from(fileList || []);
        if (incoming.length === 0) {
            return state.documents;
        }
        state.documents = mergeSelectedFiles(state.documents, incoming, state.partyType);
        return state.documents;
    }

    function applyRemoveSelectedFile(index) {
        if (isUploadQueueLocked()) {
            return state.documents;
        }
        state.documents = removeSelectedFile(state.documents, index);
        return state.documents;
    }

    function applyUploadedFileMeta(doc, uploaded) {
        if (!doc || !uploaded) {
            return doc;
        }
        if (uploaded.tmp_file) {
            doc.tmpFile = uploaded.tmp_file;
        }
        if (uploaded.mime_type) {
            doc.mimeType = uploaded.mime_type;
        }
        if (uploaded.original_name) {
            doc.originalName = uploaded.original_name;
        }
        if (uploaded.size != null && uploaded.size !== '') {
            const size = Number(uploaded.size);
            if (Number.isFinite(size)) {
                doc.size = size;
            }
        }
        return doc;
    }

    function previewAsPdf(doc) {
        if (!doc) {
            return false;
        }
        const originalType = doc.file && doc.file.type ? String(doc.file.type) : '';
        if (originalType.indexOf('image/') === 0) {
            return false;
        }
        return (doc.mimeType || '') === 'application/pdf';
    }

    function createPendingDocument(file, index, partyType) {
        return {
            index,
            file,
            originalName: file.name,
            mimeType: file.type,
            size: file.size,
            objectUrl: URL.createObjectURL(file),
            tmpFile: null,
            status: STATUS.PENDING,
            extractedData: null,
            error: null,
            reviewDecision: null,
            _partyType: partyType,
        };
    }

    function mergeSelectedFiles(existingDocs, incomingFiles, partyType) {
        const docs = Array.isArray(existingDocs) ? existingDocs.slice() : [];
        Array.from(incomingFiles || []).forEach(file => {
            if (!file) {
                return;
            }
            docs.push(createPendingDocument(file, docs.length, partyType));
        });
        return docs;
    }

    function removeSelectedFile(docs, index) {
        const next = Array.isArray(docs) ? docs.slice() : [];
        if (!Number.isInteger(index) || index < 0 || index >= next.length) {
            return next;
        }
        const [removed] = next.splice(index, 1);
        if (removed?.objectUrl) {
            URL.revokeObjectURL(removed.objectUrl);
        }
        next.forEach((doc, i) => {
            doc.index = i;
        });
        return next;
    }

    function buildSelectedFileListHtml(docs) {
        return (Array.isArray(docs) ? docs : []).map((doc, i) => {
            const file = doc.file || {};
            const name = doc.originalName || file.name || '';
            const type = doc.mimeType || file.type || '';
            const size = doc.size ?? file.size ?? 0;
            const icon = type === 'application/pdf' ? 'fa-file-pdf text-danger' : 'fa-file-image text-primary';
            const sizeMb = (size / 1024 / 1024).toFixed(2);
            const removeLabel = trans('aiscan-remove-file', {'%name%': name});
            const locked = isUploadQueueLocked();
            const disabledAttr = locked ? ' disabled aria-disabled="true"' : '';
            return `<div class="list-group-item py-1 px-2 d-flex align-items-center justify-content-between" role="listitem">
                <div class="d-flex align-items-center min-width-0">
                    <i class="fa-solid ${icon} me-2" aria-hidden="true"></i>
                    <span class="text-truncate small">${escapeHtml(name)}</span>
                </div>
                <div class="d-flex align-items-center ms-2">
                    <span class="text-muted small me-2" style="white-space:nowrap">${sizeMb} MB</span>
                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 aiscan-remove-file"
                        data-aiscan-remove-file="${i}" aria-label="${escapeAttr(removeLabel)}" title="${escapeAttr(removeLabel)}"${disabledAttr}>
                        <i class="fa-solid fa-trash" aria-hidden="true"></i>
                    </button>
                </div>
            </div>`;
        }).join('');
    }

    function renderSelectedFiles() {
        const dropZone = document.getElementById('aiscan-drop-zone');
        const fileListEl = document.getElementById('aiscan-file-list');
        const fileListBody = document.getElementById('aiscan-file-list-body');
        const fileCount = document.getElementById('aiscan-file-count');
        const uploadBtn = document.getElementById('aiscan-upload-btn');
        const fileInput = document.getElementById('aiscan-file-input');
        const count = state.documents.length;
        const locked = isUploadQueueLocked();

        if (dropZone) {
            dropZone.classList.toggle('aiscan-queue-locked', locked);
            dropZone.setAttribute('aria-disabled', locked ? 'true' : 'false');
            if (count === 0) {
                dropZone.innerHTML = dropZoneDefaultHtml;
            } else {
                const hint = locked ? trans('aiscan-uploading-file') : trans('aiscan-add-more-files');
                dropZone.innerHTML = `
                    <div>
                        <div class="fs-3 mb-2 text-success"><i class="fa-solid fa-check-circle"></i></div>
                        <div class="fw-semibold">${escapeHtml(trans('aiscan-files-selected', {'%count%': String(count)}))}</div>
                        <div class="small text-muted mt-2">${escapeHtml(hint)}</div>
                    </div>
                `;
            }
        }

        if (fileListEl && fileListBody) {
            if (count === 0) {
                fileListEl.classList.add('d-none');
                fileListBody.innerHTML = '';
            } else {
                fileListEl.classList.remove('d-none');
                fileListBody.setAttribute(
                    'aria-label',
                    trans('aiscan-files-selected', {'%count%': String(count)})
                );
                fileListBody.innerHTML = buildSelectedFileListHtml(state.documents);
            }
        }
        if (fileCount) {
            fileCount.textContent = count
                ? trans('aiscan-files-selected', {'%count%': String(count)})
                : '';
        }
        if (fileInput) {
            fileInput.disabled = locked;
        }
        if (uploadBtn && !locked) {
            uploadBtn.disabled = count === 0;
        }
    }

    function onFilesSelected(fileList) {
        if (isUploadQueueLocked()) {
            const lockedInput = document.getElementById('aiscan-file-input');
            if (lockedInput) {
                lockedInput.value = '';
            }
            return;
        }
        const incoming = Array.from(fileList || []);
        if (incoming.length === 0) {
            return;
        }

        applySelectedFiles(incoming);
        renderSelectedFiles();

        const fileInput = document.getElementById('aiscan-file-input');
        if (fileInput) {
            fileInput.value = '';
        }
    }

    async function startUploadAndAnalyze() {
        if (!state.importMode) {
            const errorEl = document.getElementById('aiscan-import-mode-error');
            if (errorEl) {
                errorEl.classList.remove('d-none');
            }
            return;
        }

        const uploadBtn = document.getElementById('aiscan-upload-btn');
        setUploadQueueLocked(true);
        renderSelectedFiles();
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-1"></i>${escapeHtml(trans('aiscan-uploading-file'))}`;

        // Save user's model choice BEFORE upload (buildProviderSelect will rebuild the dropdown)
        const userSelectedChoice = selectedModelChoice();

        // Upload in chunks to avoid PHP max_file_uploads limit
        const chunkSize = 10;
        const docs = state.documents;
        let firstData = null;

        try {
            for (let start = 0; start < docs.length; start += chunkSize) {
                const chunk = docs.slice(start, start + chunkSize);
                const formData = new FormData();
                chunk.forEach((doc, i) => {
                    formData.append('invoice_files[]', doc.file);
                    formData.append('client_indices[]', String(start + i));
                });
                const convertToPdf = document.getElementById('aiscan-convert-to-pdf');
                formData.append('convert_to_pdf', convertToPdf && convertToPdf.checked ? '1' : '0');

                const response = await apiFetch('AiScanInvoice?action=upload', {
                    method: 'POST',
                    body: formData,
                });
                const data = await readJsonResponse(response);

                if (!data.success) {
                    throw new Error(data.error || trans('aiscan-no-file-uploaded'));
                }

                if (!firstData) {
                    firstData = data;
                    state.availableProviders = data.available_providers || [data.provider];
                    state.availableModels = data.available_models || [];
                    state.extractionPrompt = data.extraction_prompt || '';
                    state.maxParallelRequests = data.max_parallel_requests || 5;
                    state.defaultProvider = userSelectedChoice.provider;
                    state.defaultModel = userSelectedChoice.model;
                    if (data.provider) {
                        state.configuredDefault = {provider: data.provider, model: data.model || ''};
                    }
                }

                const uploadedFiles = data.files || [];
                uploadedFiles.forEach(uf => {
                    const idx = start + uf.client_index;
                    const doc = docs[idx];
                    if (doc) {
                        applyUploadedFileMeta(doc, uf);
                    }
                });

                if (data.errors && data.errors.length > 0) {
                    data.errors.forEach(err => {
                        const idx = start + err.client_index;
                        const doc = docs[idx];
                        if (doc) {
                            doc.status = STATUS.FAILED;
                            doc.error = err.error;
                        }
                    });
                }
            }

            buildProviderSelect();

            // Force manual entry when no AI provider is available (#67).
            const providers = state.availableProviders || [];
            if (!providers.length) {
                state.manualEntryOnly = true;
                const manualCb = document.getElementById('aiscan-manual-entry');
                if (manualCb) {
                    manualCb.checked = true;
                }
            }

            showStep('review');
            state.currentIndex = 0;
            renderCurrentDocument();

            if (state.manualEntryOnly) {
                openAllAsManualEntry(trans('aiscan-scan-failed-no-provider'));
            } else {
                analyzeAllPending();
            }
        } catch (error) {
            setUploadQueueLocked(false);
            renderSelectedFiles();
            uploadBtn.disabled = false;
            updateUploadButtonLabel();
            alert(error.message);
        }
    }

