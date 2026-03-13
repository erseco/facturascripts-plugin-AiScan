/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

'use strict';

(() => {
    const state = {
        availableProviders: [],
        browserPromptSupported: false,
        currentFileIndex: 0,
        defaultProvider: null,
        extractionPrompt: null,
        files: [],
        initialLinesMarkup: null,
        initialObservations: '',
        initialProviderHtml: '',
        invoiceId: null,
    };

    const selectors = {
        clearBtn: 'aiscan-clear-btn',
        drawerId: 'aiscan-drawer',
        fileInput: 'aiscan-file-input',
        linesBody: 'aiscan-lines-body',
        previewArea: 'aiscan-preview-area',
        providerSelect: 'aiscan-provider-select',
        scanBtn: 'aiscan-scan-btn',
        status: 'aiscan-status',
        summary: 'aiscan-summary',
        wizardBackBtn: 'aiscan-back-btn',
        wizardNextBtn: 'aiscan-next-btn',
        wizardProgress: 'aiscan-wizard-progress',
    };

    const flow = window.AiScanFlow || createFlowFallback();
    const FALLBACK_PROMPT = 'You are an invoice extraction engine. Extract data from the provided invoice and return ONLY valid JSON with supplier, invoice, taxes, lines, confidence and warnings fields. Never invent values, use null for unknown fields.';
    const FALLBACK_TRANSLATIONS = {
        en: {
            accept: 'Accept',
            close: 'Close',
            confidence: 'Confidence',
            details: 'Details',
            'payment-method': 'Payment method',
            reference: 'Reference',
            series: 'Series',
            supplier: 'Supplier',
            quantity: 'Quantity',
            price: 'Price',
            discount: 'Discount',
            tax: 'Tax',
            description: 'Description',
            date: 'Date',
            search: 'Search',
            number: 'Number',
            subtotal: 'Subtotal',
            total: 'Total',
            expiration: 'Expiration',
            currency: 'Currency',
            summary: 'Summary',
            address: 'Address',
            email: 'Email',
            phone: 'Phone',
            name: 'Name',
            'tax-id': 'Tax ID',
            'aiscan-analyze': 'Analyze',
            'aiscan-analysis-completed': 'Analysis completed (%provider%).',
            'aiscan-analysis-started': 'Analyzing invoice with %provider%...',
            'aiscan-analyzing-browser-ai': 'Analyzing with Browser AI...',
            'aiscan-browser-ai-error': 'Browser AI error: %message%',
            'aiscan-browser-prompt-not-available': 'Browser Prompt API is not available in this browser.',
            'aiscan-clear-file': 'Remove file',
            'aiscan-delete-line': 'Delete line',
            'aiscan-document': 'Document',
            'aiscan-document-and-image-drop': 'Drop a PDF or image here',
            'aiscan-document-preview': 'Invoice preview',
            'aiscan-drag-to-resize': 'Drag to resize',
            'aiscan-drop-or-click': 'or click to select files',
            'aiscan-drawer-title': 'AiScan',
            'aiscan-file-progress': 'File %current% of %total%',
            'aiscan-file-uploaded-select-provider': 'File uploaded. Select a provider and click Analyze.',
            'aiscan-finish': 'Finish',
            'aiscan-initial-drawer-message': 'Upload a document and AiScan will map supplier, header and lines onto this page.',
            'aiscan-invoice-saved-successfully': 'Invoice saved successfully.',
            'aiscan-line-items': 'Line items',
            'aiscan-loading-provider': 'Loading...',
            'aiscan-matched-with': 'Matched with: %name%',
            'aiscan-next': 'Next',
            'aiscan-no-document-selected': 'No document selected.',
            'aiscan-no-file-uploaded': 'No file uploaded.',
            'aiscan-no-results': 'No results',
            'aiscan-provider-browser-prompt': 'Browser Prompt API (experimental)',
            'aiscan-provider-gemini': 'Google Gemini',
            'aiscan-provider-label-browser-prompt': 'Browser AI',
            'aiscan-provider-mistral': 'Mistral',
            'aiscan-provider-openai': 'OpenAI',
            'aiscan-provider-openai-compatible': 'OpenAI compatible',
            'aiscan-save': 'Save',
            'aiscan-save-and-next': 'Save and next',
            'aiscan-saving-invoice': 'Saving purchase invoice...',
            'aiscan-scanned-supplier-invoice': 'Scanned supplier invoice',
            'aiscan-supplier-ambiguous': 'Multiple supplier matches found. Select the correct supplier.',
            'aiscan-supplier-created': 'Supplier created and selected.',
            'aiscan-supplier-inline-help': 'Supplier not found. Select one normally or create a new supplier with these extracted data.',
            'aiscan-supplier-name-required': 'Supplier name is required.',
            'aiscan-supplier-unresolved': 'Supplier not found',
            'aiscan-uploading-file': 'Uploading file...',
            'aiscan-use-cloud-provider-for-image': 'Browser AI cannot analyze images. Please select a cloud provider.',
            'aiscan-use-cloud-provider-for-pdf': 'Could not extract text from this PDF. Please select a cloud provider.',
            'aiscan-validation-warnings': 'Warnings',
            'aiscan-warehouse': 'Warehouse',
            'aiscan-warehouse-required': 'Warehouse is required to create a new purchase invoice.',
            'aiscan-create-supplier': 'Create supplier',
            'scan-invoice': 'Scan Invoice',
        },
        es: {
            accept: 'Aceptar',
            close: 'Cerrar',
            confidence: 'Confianza',
            details: 'Detalle',
            'payment-method': 'Forma de pago',
            reference: 'Referencia',
            series: 'Serie',
            supplier: 'Proveedor',
            quantity: 'Cantidad',
            price: 'Precio',
            discount: 'Descuento',
            tax: 'Impuesto',
            description: 'Descripción',
            date: 'Fecha',
            search: 'Buscar',
            number: 'Número',
            subtotal: 'Subtotal',
            total: 'Total',
            expiration: 'Vencimiento',
            currency: 'Divisa',
            summary: 'Resumen',
            address: 'Dirección',
            email: 'Email',
            phone: 'Teléfono',
            name: 'Nombre',
            'tax-id': 'CIF/NIF',
            'aiscan-analyze': 'Analizar',
            'aiscan-analysis-completed': 'Análisis completado (%provider%).',
            'aiscan-analysis-started': 'Analizando factura con %provider%...',
            'aiscan-analyzing-browser-ai': 'Analizando con Browser AI...',
            'aiscan-browser-ai-error': 'Error de Browser AI: %message%',
            'aiscan-browser-prompt-not-available': 'La API Browser Prompt no está disponible en este navegador.',
            'aiscan-clear-file': 'Quitar archivo',
            'aiscan-delete-line': 'Eliminar línea',
            'aiscan-document': 'Documento',
            'aiscan-document-and-image-drop': 'Suelta aquí un PDF o una imagen',
            'aiscan-document-preview': 'Vista previa de la factura',
            'aiscan-drag-to-resize': 'Arrastra para redimensionar',
            'aiscan-drop-or-click': 'o haz clic para seleccionar archivos',
            'aiscan-drawer-title': 'AiScan',
            'aiscan-file-progress': 'Archivo %current% de %total%',
            'aiscan-file-uploaded-select-provider': 'Archivo subido. Selecciona un proveedor y pulsa Analizar.',
            'aiscan-finish': 'Finalizar',
            'aiscan-initial-drawer-message': 'Sube un documento y AiScan rellenará proveedor, cabecera y líneas en esta página.',
            'aiscan-invoice-saved-successfully': 'Factura guardada correctamente.',
            'aiscan-line-items': 'Líneas',
            'aiscan-loading-provider': 'Cargando...',
            'aiscan-matched-with': 'Coincide con: %name%',
            'aiscan-next': 'Siguiente',
            'aiscan-no-document-selected': 'No hay documento seleccionado.',
            'aiscan-no-file-uploaded': 'No se ha subido ningún archivo.',
            'aiscan-no-results': 'Sin resultados',
            'aiscan-provider-browser-prompt': 'Browser Prompt API (experimental)',
            'aiscan-provider-gemini': 'Google Gemini',
            'aiscan-provider-label-browser-prompt': 'Browser AI',
            'aiscan-provider-mistral': 'Mistral',
            'aiscan-provider-openai': 'OpenAI',
            'aiscan-provider-openai-compatible': 'OpenAI compatible',
            'aiscan-save': 'Guardar',
            'aiscan-save-and-next': 'Guardar y siguiente',
            'aiscan-saving-invoice': 'Guardando factura de proveedor...',
            'aiscan-scanned-supplier-invoice': 'Factura de proveedor escaneada',
            'aiscan-supplier-ambiguous': 'Se han encontrado varios proveedores coincidentes. Selecciona el correcto.',
            'aiscan-supplier-created': 'Proveedor creado y seleccionado.',
            'aiscan-supplier-inline-help': 'Proveedor no localizado. Selecciónalo normalmente o crea uno con estos datos extraídos.',
            'aiscan-supplier-name-required': 'El nombre del proveedor es obligatorio.',
            'aiscan-supplier-unresolved': 'Proveedor no localizado',
            'aiscan-uploading-file': 'Subiendo archivo...',
            'aiscan-use-cloud-provider-for-image': 'Browser AI no puede analizar imágenes. Selecciona un proveedor en la nube.',
            'aiscan-use-cloud-provider-for-pdf': 'No se pudo extraer texto de este PDF. Selecciona un proveedor en la nube.',
            'aiscan-validation-warnings': 'Avisos',
            'aiscan-warehouse': 'Almacén',
            'aiscan-warehouse-required': 'El almacén es obligatorio para crear una nueva factura de proveedor.',
            'aiscan-create-supplier': 'Crear proveedor',
            'scan-invoice': 'Escanear Factura',
        },
    };

    document.addEventListener('DOMContentLoaded', () => {
        if (!isEditFacturaProveedorPage()) {
            return;
        }

        rememberInitialPageState();
        suppressInitialSupplierModal();
        checkBrowserPromptSupport();
        ensureDrawer();
        syncDrawerOffset();
        bindDrawerEvents();
        hookNativeSave();
        renderCurrentFile();
    });

    function createFlowFallback() {
        function cloneValue(value) {
            if (value == null) {
                return value;
            }

            return JSON.parse(JSON.stringify(value));
        }

        return {
            cloneValue,
            createWizardEntries(uploadedFiles, invoiceId) {
                return (Array.isArray(uploadedFiles) ? uploadedFiles : []).map((file, index) => ({
                    clientIndex: Number.isInteger(file.client_index) ? file.client_index : index,
                    extractedData: null,
                    file: null,
                    invoiceId: index === 0 ? (invoiceId || '') : '',
                    isSaved: false,
                    isSaving: false,
                    mimeType: file.mime_type || '',
                    objectUrl: null,
                    originalName: file.original_name || '',
                    selectedProvider: '',
                    size: file.size || 0,
                    tmpFile: file.tmp_file || '',
                }));
            },
            getSaveInvoiceId(entry) {
                return entry && entry.invoiceId ? String(entry.invoiceId) : '';
            },
            getWizardMeta(entries, currentIndex) {
                const total = Array.isArray(entries) ? entries.length : 0;
                const safeIndex = total === 0 ? 0 : Math.min(Math.max(currentIndex || 0, 0), total - 1);
                const isMultiFile = total > 1;
                const isLast = total > 0 ? safeIndex === total - 1 : true;

                return {
                    canGoBack: isMultiFile && safeIndex > 0,
                    canGoNext: isMultiFile && safeIndex < total - 1,
                    currentIndex: safeIndex,
                    currentPosition: total === 0 ? 0 : safeIndex + 1,
                    isLast,
                    isMultiFile,
                    primaryAction: isMultiFile ? (isLast ? 'finish' : 'saveAndNext') : 'save',
                    total,
                };
            },
            markEntrySaved(entry, invoiceId) {
                return {
                    ...entry,
                    invoiceId: invoiceId || (entry && entry.invoiceId ? String(entry.invoiceId) : ''),
                    isSaved: true,
                    isSaving: false,
                };
            },
            normalizeUploadResponse(response) {
                if (response && Array.isArray(response.files) && response.files.length > 0) {
                    return response.files.map(cloneValue);
                }

                if (response && response.tmp_file) {
                    return [cloneValue({
                        client_index: response.client_index ?? 0,
                        mime_type: response.mime_type,
                        original_name: response.original_name,
                        size: response.size,
                        tmp_file: response.tmp_file,
                    })];
                }

                return [];
            },
        };
    }

    function isEditFacturaProveedorPage() {
        const path = window.location.pathname || '';
        if (path.includes('EditFacturaProveedor')) {
            return true;
        }

        return Boolean(document.querySelector('input[name="codproveedor"]') || document.getElementById('findSupplierModal'));
    }

    function currentLang() {
        const lang = (
            document.documentElement.lang ||
            document.body?.getAttribute('lang') ||
            navigator.language ||
            'en'
        ).toLowerCase();

        return lang.startsWith('es') ? 'es' : 'en';
    }

    function trans(key, replacements = {}) {
        let value = key;

        if (window.i18n && typeof window.i18n.trans === 'function') {
            value = window.i18n.trans(key);
        }

        if (value === key) {
            value = FALLBACK_TRANSLATIONS[currentLang()][key] || key;
        }

        value = typeof value === 'string' ? value : String(value);
        Object.entries(replacements).forEach(([placeholder, replacement]) => {
            value = value.replaceAll(placeholder, replacement == null ? '' : String(replacement));
        });
        return value;
    }

    function providerLabel(provider) {
        const labels = {
            'browser-prompt': trans('aiscan-provider-label-browser-prompt'),
            gemini: trans('aiscan-provider-gemini'),
            mistral: trans('aiscan-provider-mistral'),
            openai: trans('aiscan-provider-openai'),
            'openai-compatible': trans('aiscan-provider-openai-compatible'),
        };

        return labels[provider] || provider || trans('aiscan-loading-provider');
    }

    function rememberInitialPageState() {
        state.invoiceId = getInvoiceId();
        state.initialLinesMarkup = document.getElementById('purchasesFormLines')?.innerHTML || '';
        state.initialObservations = document.querySelector('textarea[name="observaciones"]')?.value || '';
        state.initialProviderHtml = getProviderField()?.column?.innerHTML || '';
    }

    function suppressInitialSupplierModal() {
        const hide = () => {
            const modalEl = document.getElementById('findSupplierModal');
            if (!modalEl) {
                return;
            }

            if (window.bootstrap && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }

            modalEl.classList.remove('show');
            modalEl.style.display = 'none';
            document.body.classList.remove('modal-open');
            document.querySelectorAll('.modal-backdrop').forEach((node) => node.remove());
        };

        window.setTimeout(hide, 0);
        window.setTimeout(hide, 300);
    }

    async function checkBrowserPromptSupport() {
        try {
            if (typeof LanguageModel !== 'undefined') {
                const availability = await LanguageModel.availability({
                    expectedInputs: [{type: 'text', languages: ['en', 'es']}],
                    expectedOutputs: [{type: 'text', languages: ['en']}],
                });
                state.browserPromptSupported = availability !== 'unavailable';
            }
        } catch (error) {
            state.browserPromptSupported = false;
        }
    }

    function ensureDrawer() {
        if (document.getElementById(selectors.drawerId)) {
            return;
        }

        document.body.classList.add('aiscan-page-ready');
        document.body.insertAdjacentHTML('beforeend', `
            <aside id="${selectors.drawerId}" class="aiscan-drawer">
                <div class="aiscan-drawer-handle" id="aiscan-drawer-handle" title="${escapeAttribute(trans('aiscan-drag-to-resize'))}">
                    <span></span>
                </div>
                <div class="aiscan-drawer-panel">
                    <div class="aiscan-drawer-header">
                        <div>
                            <div class="aiscan-drawer-title">${escapeHtml(trans('aiscan-drawer-title'))}</div>
                            <div class="small text-muted" id="${selectors.wizardProgress}"></div>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <select class="form-select form-select-sm" id="${selectors.providerSelect}"></select>
                            <button type="button" class="btn btn-primary btn-sm" id="${selectors.scanBtn}" disabled>
                                <i class="fa-solid fa-wand-magic-sparkles me-1"></i>${escapeHtml(trans('aiscan-analyze'))}
                            </button>
                        </div>
                    </div>
                    <div class="aiscan-drawer-toolbar">
                        <input id="${selectors.fileInput}" type="file" class="d-none" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="aiscan-upload-btn">
                            <i class="fa-solid fa-file-arrow-up me-1"></i>${escapeHtml(trans('aiscan-document'))}
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="${selectors.clearBtn}" disabled>
                            <i class="fa-solid fa-trash-can me-1"></i>${escapeHtml(trans('aiscan-clear-file'))}
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="${selectors.wizardBackBtn}" disabled>
                            <i class="fa-solid fa-arrow-left"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="${selectors.wizardNextBtn}" disabled>
                            <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </div>
                    <div class="aiscan-drawer-preview" id="${selectors.previewArea}">${dropAreaHtml()}</div>
                    <div class="aiscan-drawer-summary" id="${selectors.summary}">
                        <p class="text-muted mb-0">${escapeHtml(trans('aiscan-initial-drawer-message'))}</p>
                    </div>
                    <div id="${selectors.status}" class="aiscan-drawer-status"></div>
                </div>
            </aside>
        `);
    }

    function dropAreaHtml() {
        return `
            <div class="aiscan-drop-area d-flex align-items-center justify-content-center text-center" id="aiscan-drop-zone">
                <div>
                    <div class="fs-3 mb-2 text-muted"><i class="fa-solid fa-file-arrow-up"></i></div>
                    <div>${escapeHtml(trans('aiscan-document-and-image-drop'))}</div>
                    <div class="small text-muted mt-1">${escapeHtml(trans('aiscan-drop-or-click'))}</div>
                </div>
            </div>
        `;
    }

    function bindDrawerEvents() {
        const drawer = document.getElementById(selectors.drawerId);
        const previewArea = document.getElementById(selectors.previewArea);
        const fileInput = document.getElementById(selectors.fileInput);
        const uploadBtn = document.getElementById('aiscan-upload-btn');
        const providerSelect = document.getElementById(selectors.providerSelect);
        const scanBtn = document.getElementById(selectors.scanBtn);
        const clearBtn = document.getElementById(selectors.clearBtn);
        const backBtn = document.getElementById(selectors.wizardBackBtn);
        const nextBtn = document.getElementById(selectors.wizardNextBtn);

        bindDrawerResize(drawer);

        uploadBtn.addEventListener('click', () => fileInput.click());
        previewArea.addEventListener('click', (event) => {
            if (document.getElementById('aiscan-drop-zone') && !event.target.closest('button')) {
                fileInput.click();
            }
        });
        previewArea.addEventListener('dragover', (event) => {
            event.preventDefault();
            document.getElementById('aiscan-drop-zone')?.classList.add('aiscan-drag-over');
        });
        previewArea.addEventListener('dragleave', () => {
            document.getElementById('aiscan-drop-zone')?.classList.remove('aiscan-drag-over');
        });
        previewArea.addEventListener('drop', (event) => {
            event.preventDefault();
            document.getElementById('aiscan-drop-zone')?.classList.remove('aiscan-drag-over');
            if (event.dataTransfer.files.length > 0) {
                handleFiles(event.dataTransfer.files);
            }
        });
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                handleFiles(fileInput.files);
            }
        });
        providerSelect.addEventListener('change', () => {
            const entry = getCurrentFileEntry();
            if (entry) {
                entry.selectedProvider = providerSelect.value;
            }
        });
        scanBtn.addEventListener('click', () => {
            const selected = providerSelect.value || '';
            if (selected === 'browser-prompt') {
                analyzeWithBrowserPrompt();
            } else {
                analyzeDocument(selected || undefined);
            }
        });
        clearBtn.addEventListener('click', clearFile);
        backBtn.addEventListener('click', () => navigateToFile(state.currentFileIndex - 1));
        nextBtn.addEventListener('click', () => navigateToFile(state.currentFileIndex + 1));

        document.addEventListener('click', handleGlobalClick);
        document.addEventListener('input', handleGlobalInput);
        document.addEventListener('keydown', handleGlobalKeydown);
        window.addEventListener('resize', syncDrawerOffset);
    }

    function syncDrawerOffset() {
        const nav = document.querySelector('nav.navbar, .navbar.fixed-top, body > nav, header nav');
        const topOffset = nav ? Math.round(nav.getBoundingClientRect().bottom) : 0;
        document.documentElement.style.setProperty('--aiscan-top-offset', `${Math.max(0, topOffset)}px`);
    }

    function hookNativeSave() {
        const tryHook = () => {
            if (typeof window.purchasesFormAction !== 'function') {
                return false;
            }

            const original = window.purchasesFormAction;
            window.purchasesFormAction = function (action, ...args) {
                if (action === 'save-doc' && hasAiScanPendingData()) {
                    saveCurrentDocument();
                    return;
                }

                return original.call(this, action, ...args);
            };

            return true;
        };

        if (!tryHook()) {
            window.setTimeout(tryHook, 500);
            window.setTimeout(tryHook, 1500);
        }
    }

    function hasAiScanPendingData() {
        const entry = getCurrentFileEntry();
        return Boolean(entry && entry.extractedData && !entry.isSaved && !entry.isSaving);
    }

    function bindDrawerResize(drawer) {
        const handle = document.getElementById('aiscan-drawer-handle');
        if (!handle || !drawer) {
            return;
        }

        let dragging = false;
        handle.addEventListener('mousedown', (event) => {
            event.preventDefault();
            dragging = true;
            document.body.classList.add('aiscan-drawer-resizing');
        });

        document.addEventListener('mousemove', (event) => {
            if (!dragging) {
                return;
            }

            const width = Math.min(Math.max(window.innerWidth - event.clientX, 320), 840);
            drawer.style.width = `${width}px`;
        });

        document.addEventListener('mouseup', () => {
            if (!dragging) {
                return;
            }

            dragging = false;
            document.body.classList.remove('aiscan-drawer-resizing');
        });
    }

    function getInvoiceId() {
        const params = new URLSearchParams(window.location.search);
        return params.get('code') || document.querySelector('input[name="code"]')?.value || '';
    }

    function getCurrentFileEntry() {
        return state.files[state.currentFileIndex] || null;
    }

    function handleFiles(fileList) {
        const files = Array.from(fileList || []);
        if (files.length === 0) {
            return;
        }

        document.getElementById(selectors.fileInput).value = '';
        uploadFiles(files);
    }

    function uploadFiles(files) {
        const formData = new FormData();
        files.forEach((file) => formData.append('invoice_files[]', file));
        setStatus(trans('aiscan-uploading-file'), 'info');

        fetch('AiScanInvoice?action=upload', {
            method: 'POST',
            body: formData,
        })
            .then((response) => response.json())
            .then((data) => {
                const uploadedFiles = flow.normalizeUploadResponse(data);
                if (uploadedFiles.length === 0) {
                    throw new Error(data.error || trans('aiscan-no-file-uploaded'));
                }

                setUploadedFiles(files, uploadedFiles, data);
                setStatus(trans('aiscan-file-uploaded-select-provider'), 'success');
            })
            .catch((error) => setStatus(error.message, 'danger'));
    }

    function setUploadedFiles(localFiles, uploadedFiles, response) {
        state.files.forEach((file) => {
            if (file.objectUrl) {
                URL.revokeObjectURL(file.objectUrl);
            }
        });

        state.defaultProvider = response.provider || 'openai';
        state.availableProviders = response.available_providers || [state.defaultProvider];
        state.extractionPrompt = response.extraction_prompt || FALLBACK_PROMPT;
        state.currentFileIndex = 0;
        state.files = flow.createWizardEntries(uploadedFiles, state.invoiceId).map((entry) => {
            const localFile = localFiles[entry.clientIndex] || null;
            return {
                ...entry,
                file: localFile,
                mimeType: entry.mimeType || localFile?.type || '',
                objectUrl: localFile ? URL.createObjectURL(localFile) : null,
                selectedProvider: state.defaultProvider,
            };
        });

        renderCurrentFile();
    }

    function clearFile() {
        const entry = getCurrentFileEntry();
        if (!entry || entry.isSaving || entry.isSaved) {
            return;
        }

        if (entry.objectUrl) {
            URL.revokeObjectURL(entry.objectUrl);
        }

        state.files.splice(state.currentFileIndex, 1);
        if (state.currentFileIndex >= state.files.length) {
            state.currentFileIndex = Math.max(0, state.files.length - 1);
        }

        if (state.files.length === 0) {
            resetDrawerAndPage();
            return;
        }

        renderCurrentFile();
    }

    function resetDrawerAndPage() {
        state.files.forEach((file) => {
            if (file.objectUrl) {
                URL.revokeObjectURL(file.objectUrl);
            }
        });

        state.files = [];
        state.currentFileIndex = 0;
        renderCurrentFile();
        resetNativeInvoiceView();
    }

    function navigateToFile(index) {
        const meta = flow.getWizardMeta(state.files, state.currentFileIndex);
        if (index < 0 || index >= meta.total || index === state.currentFileIndex) {
            return;
        }

        persistCurrentPageState();
        state.currentFileIndex = index;
        renderCurrentFile();
    }

    function renderCurrentFile() {
        const entry = getCurrentFileEntry();
        const previewArea = document.getElementById(selectors.previewArea);
        const summary = document.getElementById(selectors.summary);
        const clearBtn = document.getElementById(selectors.clearBtn);
        const scanBtn = document.getElementById(selectors.scanBtn);

        buildProviderSelect();

        if (!entry) {
            previewArea.innerHTML = dropAreaHtml();
            summary.innerHTML = `<p class="text-muted mb-0">${escapeHtml(trans('aiscan-initial-drawer-message'))}</p>`;
            clearBtn.disabled = true;
            scanBtn.disabled = true;
            resetNativeInvoiceView();
            updateWizardControls();
            return;
        }

        showPreview(entry);
        clearBtn.disabled = entry.isSaving || entry.isSaved;
        scanBtn.disabled = !entry.tmpFile || entry.isSaving;

        if (entry.extractedData) {
            applyExtractedToPage(entry.extractedData);
            renderDrawerSummary(entry.extractedData);
        } else {
            summary.innerHTML = `<p class="text-muted mb-0">${escapeHtml(trans('aiscan-no-document-selected'))}</p>`;
        }

        updateWizardControls();
    }

    function buildProviderSelect() {
        const select = document.getElementById(selectors.providerSelect);
        if (!select) {
            return;
        }

        const entry = getCurrentFileEntry();
        const selectedProvider = entry?.selectedProvider || state.defaultProvider || 'openai';
        select.innerHTML = '';

        const providers = state.availableProviders && state.availableProviders.length > 0
            ? state.availableProviders
            : ['openai'];

        providers.forEach((provider) => {
            const option = document.createElement('option');
            option.value = provider;
            option.textContent = providerLabel(provider);
            option.selected = provider === selectedProvider;
            select.appendChild(option);
        });

        if (state.browserPromptSupported) {
            const option = document.createElement('option');
            option.value = 'browser-prompt';
            option.textContent = providerLabel('browser-prompt');
            option.selected = selectedProvider === 'browser-prompt';
            select.appendChild(option);
        }
    }

    function showPreview(entry) {
        const previewArea = document.getElementById(selectors.previewArea);
        previewArea.innerHTML = '';

        if (!entry.objectUrl && entry.file) {
            entry.objectUrl = URL.createObjectURL(entry.file);
        }

        if (!entry.objectUrl) {
            previewArea.innerHTML = dropAreaHtml();
            return;
        }

        if (entry.mimeType === 'application/pdf') {
            previewArea.innerHTML = `<iframe src="${entry.objectUrl}" title="${escapeAttribute(trans('aiscan-document-preview'))}"></iframe>`;
        } else {
            previewArea.innerHTML = `<img src="${entry.objectUrl}" alt="${escapeAttribute(trans('aiscan-document-preview'))}">`;
        }
    }

    function renderDrawerSummary(data) {
        const summary = document.getElementById(selectors.summary);
        const supplier = data.supplier || {};
        const invoice = data.invoice || {};
        const warnings = Array.isArray(data._validation_errors) ? data._validation_errors : [];
        const warehouseField = document.querySelector('[name="codalmacen"]');
        const warehouseValue = invoice.warehouse_code || warehouseField?.value || '';

        summary.innerHTML = `
            <div class="small">
                <div><strong>${escapeHtml(trans('supplier'))}:</strong> ${escapeHtml(supplier.name || trans('aiscan-supplier-unresolved'))}</div>
                <div><strong>${escapeHtml(trans('number'))}:</strong> ${escapeHtml(invoice.number || '')}</div>
                <div><strong>${escapeHtml(trans('date'))}:</strong> ${escapeHtml(invoice.issue_date || '')}</div>
                <div><strong>${escapeHtml(trans('total'))}:</strong> ${escapeHtml(invoice.total ?? '')}</div>
            </div>
            <div class="mt-3">
                <label class="form-label small mb-1" for="invoice_warehouse_code">${escapeHtml(trans('aiscan-warehouse'))}</label>
                <input type="text" class="form-control form-control-sm" id="invoice_warehouse_code"
                    value="${escapeAttribute(warehouseValue)}" required aria-required="true">
            </div>
            ${warnings.length > 0 ? `
                <div class="alert alert-warning py-2 mt-2 mb-0 small">
                    <strong>${escapeHtml(trans('aiscan-validation-warnings'))}</strong>
                    <ul class="mb-0">${warnings.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>
                </div>
            ` : ''}
        `;
    }

    function updateWizardControls() {
        const entry = getCurrentFileEntry();
        const meta = flow.getWizardMeta(state.files, state.currentFileIndex);
        const progress = document.getElementById(selectors.wizardProgress);
        const backBtn = document.getElementById(selectors.wizardBackBtn);
        const nextBtn = document.getElementById(selectors.wizardNextBtn);

        progress.textContent = meta.isMultiFile
            ? trans('aiscan-file-progress', {
                '%current%': String(meta.currentPosition),
                '%total%': String(meta.total),
            })
            : '';
        backBtn.disabled = !meta.canGoBack || Boolean(entry?.isSaving);
        nextBtn.disabled = !meta.canGoNext || Boolean(entry?.isSaving);
    }

    function persistCurrentPageState() {
        const entry = getCurrentFileEntry();
        if (!entry || !entry.extractedData) {
            return;
        }

        const data = collectPageData(entry.extractedData);
        if (data) {
            entry.extractedData = data;
        }
    }

    function analyzeDocument(provider) {
        const entry = getCurrentFileEntry();
        if (!entry?.tmpFile) {
            return;
        }

        const selectedProvider = provider || entry.selectedProvider || state.defaultProvider || 'openai';
        entry.selectedProvider = selectedProvider;
        setStatus(trans('aiscan-analysis-started', {'%provider%': providerLabel(selectedProvider)}), 'info');
        document.getElementById(selectors.scanBtn).disabled = true;

        const params = new URLSearchParams({
            action: 'analyze',
            mime_type: entry.mimeType || '',
            tmp_file: entry.tmpFile,
        });
        if (selectedProvider) {
            params.set('provider', selectedProvider);
        }

        fetch(`AiScanInvoice?${params.toString()}`)
            .then((response) => response.json())
            .then((data) => {
                if (data.error) {
                    throw new Error(data.error);
                }

                entry.extractedData = flow.cloneValue(data.data);
                renderCurrentFile();
                setStatus(trans('aiscan-analysis-completed', {'%provider%': providerLabel(selectedProvider)}), 'success');
            })
            .catch((error) => setStatus(error.message, 'danger'))
            .finally(() => {
                document.getElementById(selectors.scanBtn).disabled = false;
                updateWizardControls();
            });
    }

    async function analyzeWithBrowserPrompt() {
        const entry = getCurrentFileEntry();
        if (!entry) {
            return;
        }

        if (typeof LanguageModel === 'undefined') {
            setStatus(trans('aiscan-browser-prompt-not-available'), 'danger');
            return;
        }

        entry.selectedProvider = 'browser-prompt';
        setStatus(trans('aiscan-analyzing-browser-ai'), 'info');

        try {
            const session = await LanguageModel.create({
                expectedInputs: [{type: 'text', languages: ['en', 'es']}],
                expectedOutputs: [{type: 'text', languages: ['en']}],
                initialPrompts: [
                    {role: 'system', content: 'You are an expert invoice data extractor. You always respond with valid JSON only, no markdown.'},
                ],
            });
            const textContent = await fetchDocumentText(entry);
            if (!textContent) {
                throw new Error(entry.mimeType?.startsWith('image/')
                    ? trans('aiscan-use-cloud-provider-for-image')
                    : trans('aiscan-use-cloud-provider-for-pdf'));
            }

            const rawJson = await session.prompt((state.extractionPrompt || FALLBACK_PROMPT) + '\n\nDocument content:\n' + textContent);
            session.destroy();

            const cleaned = rawJson.replace(/^```(?:json)?\n?/m, '').replace(/\n?```$/m, '').trim();
            const data = JSON.parse(cleaned);
            data._provider = 'browser-prompt';
            data._validation_errors = [];

            entry.extractedData = flow.cloneValue(data);
            renderCurrentFile();
            setStatus(trans('aiscan-analysis-completed', {'%provider%': providerLabel('browser-prompt')}), 'success');
        } catch (error) {
            setStatus(trans('aiscan-browser-ai-error', {'%message%': error.message}), 'danger');
        } finally {
            updateWizardControls();
        }
    }

    async function fetchDocumentText(entry) {
        if (!entry?.tmpFile || (entry.mimeType && entry.mimeType.startsWith('image/'))) {
            return null;
        }

        try {
            const params = new URLSearchParams({action: 'get-text', tmp_file: entry.tmpFile});
            const response = await fetch(`AiScanInvoice?${params.toString()}`);
            const data = await response.json();
            if (data.text && data.text.trim().length > 10) {
                return data.text;
            }
        } catch (error) {
            // ignore
        }

        return entry.objectUrl && entry.mimeType === 'application/pdf'
            ? extractPdfTextClientSide(entry.objectUrl)
            : null;
    }

    async function extractPdfTextClientSide(url) {
        try {
            const PDFJS_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.4.168';
            if (!window._pdfjsLib) {
                window._pdfjsLib = await import(`${PDFJS_CDN}/pdf.min.mjs`);
                window._pdfjsLib.GlobalWorkerOptions.workerSrc = `${PDFJS_CDN}/pdf.worker.min.mjs`;
            }

            const pdf = await window._pdfjsLib.getDocument(url).promise;
            const pages = [];
            for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
                const page = await pdf.getPage(pageNumber);
                const content = await page.getTextContent();
                pages.push(content.items.map((item) => item.str).join(' '));
            }

            const text = pages.join('\n').trim();
            return text.length > 10 ? text : null;
        } catch (error) {
            return null;
        }
    }

    function applyExtractedToPage(data) {
        const supplier = data.supplier || {};
        applySupplierToPage(supplier);

        if (isSupplierResolved(supplier)) {
            applyInvoiceToPage(data.invoice || {});
            queuePendingLines();
        }
    }

    function isSupplierResolved(supplier) {
        return Boolean(supplier && (supplier.matched_supplier_id || supplier.id));
    }

    function getProviderField() {
        const hidden = document.querySelector('input[name="codproveedor"]');
        if (!hidden) {
            return null;
        }

        const block = hidden.closest('.mb-2');
        const column = block?.closest('[class*="col-"]') || block?.parentElement || hidden.parentElement;
        const text = block?.querySelector('input[readonly]') || block?.querySelector('input.form-control');
        const link = block?.querySelector('a[href*="EditProveedor"]');
        return {block, column, hidden, link, text};
    }

    function getSupplierHelper() {
        return document.getElementById('aiscan-supplier-helper');
    }

    function ensureSupplierHelper(supplier) {
        const providerField = getProviderField();
        if (!providerField?.column) {
            return null;
        }

        let helper = getSupplierHelper();
        if (!helper) {
            providerField.column.insertAdjacentHTML('afterend', `
                <div class="col-12" id="aiscan-supplier-helper">
                    <div class="card border-warning mt-2">
                        <div class="card-body py-3">
                            <div class="alert alert-warning py-2 small mb-3" id="aiscan-supplier-helper-status"></div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small mb-1" for="aiscan-supplier-name">${escapeHtml(trans('name'))}</label>
                                    <input type="text" class="form-control form-control-sm" id="aiscan-supplier-name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small mb-1" for="aiscan-supplier-taxid">${escapeHtml(trans('tax-id'))}</label>
                                    <input type="text" class="form-control form-control-sm" id="aiscan-supplier-taxid">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small mb-1" for="aiscan-supplier-email">${escapeHtml(trans('email'))}</label>
                                    <input type="text" class="form-control form-control-sm" id="aiscan-supplier-email">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small mb-1" for="aiscan-supplier-phone">${escapeHtml(trans('phone'))}</label>
                                    <input type="text" class="form-control form-control-sm" id="aiscan-supplier-phone">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1" for="aiscan-supplier-address">${escapeHtml(trans('address'))}</label>
                                    <textarea class="form-control form-control-sm" id="aiscan-supplier-address" rows="2"></textarea>
                                </div>
                            </div>
                            <div class="d-flex gap-2 flex-wrap mt-3">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="aiscan-supplier-search-btn">
                                    <i class="fa-solid fa-magnifying-glass me-1"></i>${escapeHtml(trans('search'))}
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" id="aiscan-create-supplier-btn">
                                    <i class="fa-solid fa-user-plus me-1"></i>${escapeHtml(trans('aiscan-create-supplier'))}
                                </button>
                            </div>
                            <div class="list-group mt-2" id="aiscan-supplier-results"></div>
                        </div>
                    </div>
                </div>
            `);
            helper = getSupplierHelper();
        }

        setSupplierHelperValues(supplier);
        return helper;
    }

    function setSupplierHelperValues(supplier) {
        setValue('aiscan-supplier-name', supplier.name || '');
        setValue('aiscan-supplier-taxid', supplier.tax_id || '');
        setValue('aiscan-supplier-email', supplier.email || '');
        setValue('aiscan-supplier-phone', supplier.phone || '');
        setValue('aiscan-supplier-address', supplier.address || '');

        const statusEl = document.getElementById('aiscan-supplier-helper-status');
        if (!statusEl) {
            return;
        }

        const text = supplier.match_status === 'ambiguous'
            ? trans('aiscan-supplier-ambiguous')
            : trans('aiscan-supplier-inline-help');
        statusEl.textContent = text;
    }

    function applySupplierToPage(supplier) {
        const providerField = getProviderField();
        if (!providerField) {
            return;
        }

        if (supplier.matched_supplier_id && (supplier.matched_name || supplier.name)) {
            applyMatchedSupplier({
                id: supplier.matched_supplier_id,
                name: supplier.matched_name || supplier.name || '',
                tax_id: supplier.tax_id || '',
            }, false);
        } else {
            if (!providerField.hidden.value) {
                if (providerField.text) {
                    providerField.text.value = '';
                }
            }
            resetInvoiceWorkspace();
            ensureSupplierHelper(supplier);
        }

        const detailName = document.querySelector('input[name="nombre"]');
        const detailTax = document.querySelector('input[name="cifnif"]');
        if (detailName && !detailName.value) {
            detailName.value = supplier.name || '';
        }
        if (detailTax && !detailTax.value) {
            detailTax.value = supplier.tax_id || '';
        }
    }

    function hideSupplierHelper() {
        const helper = getSupplierHelper();
        if (helper) {
            helper.remove();
        }
    }

    function applyInvoiceToPage(invoice) {
        setDateValue('input[name="fecha"]', invoice.issue_date || '');
        setFormValue('input[name="numproveedor"]', invoice.number || '');
        setFormValue('textarea[name="observaciones"]', invoice.summary || state.initialObservations || '');
        setSelectValue('select[name="codserie"]', invoice.series || '');
        setSelectValue('select[name="codpago"]', invoice.payment_method || invoice.payment_terms || '');
        setSelectValue('select[name="coddivisa"]', invoice.currency || '');
        setFormValue('input[name="nombre"]', readValue('aiscan-supplier-name') || invoice.supplier_name || '');
        setFormValue('input[name="cifnif"]', readValue('aiscan-supplier-taxid') || invoice.supplier_tax_id || '');
    }

    function queuePendingLines() {
        const entry = getCurrentFileEntry();
        if (!entry?.extractedData) {
            return;
        }

        window.clearTimeout(state.pendingLinesTimer);
        state.pendingLinesTimer = window.setTimeout(() => {
            waitForInvoiceWorkspace(12);
        }, 150);
    }

    function waitForInvoiceWorkspace(remainingAttempts) {
        const entry = getCurrentFileEntry();
        if (!entry?.extractedData) {
            return;
        }

        const container = document.getElementById('purchasesFormLines');
        if (container) {
            applyLinesToPage(Array.isArray(entry.extractedData.lines) ? entry.extractedData.lines : []);
            return;
        }

        if (remainingAttempts <= 0) {
            return;
        }

        state.pendingLinesTimer = window.setTimeout(() => {
            waitForInvoiceWorkspace(remainingAttempts - 1);
        }, 200);
    }

    function normalizeLines(lines) {
        const safeLines = Array.isArray(lines) && lines.length > 0 ? lines : [{
            description: trans('aiscan-scanned-supplier-invoice'),
            discount: 0,
            quantity: 1,
            selected_reference: '',
            sku: '',
            tax_rate: 0,
            unit_price: 0,
        }];

        return safeLines.map((line, index) => ({
            description: line.description || '',
            discount: line.discount ?? 0,
            id: `n${index + 1}`,
            product_description: line.product_description || '',
            quantity: line.quantity ?? 1,
            selected_reference: line.selected_reference || '',
            sku: line.sku || '',
            tax_rate: line.tax_rate ?? 0,
            unit_price: line.unit_price ?? 0,
        }));
    }

    function applyLinesToPage(lines) {
        const container = document.getElementById('purchasesFormLines');
        if (!container) {
            return;
        }

        const normalizedLines = normalizeLines(lines);
        container.innerHTML = `
            <div class="container-fluid d-none d-lg-block pt-3">
                <div class="row g-2 border-bottom">
                    <div class="col-lg-2">Referencia</div>
                    <div class="col-lg">Descripción</div>
                    <div class="col-lg-1 text-end">Cantidad</div>
                    <div class="col-lg-1 text-end">Precio</div>
                    <div class="col-lg-1 text-center">% Dto.</div>
                    <div class="col-lg-1 text-end">Impuesto</div>
                    <div class="col-lg-auto"></div>
                </div>
            </div>
            <div class="container-fluid" id="${selectors.linesBody}">
                ${normalizedLines.map((line, index) => renderNativeLine(line, index)).join('')}
            </div>
        `;
    }

    function renderNativeLine(line, index) {
        return `
            <div class="row g-2 align-items-start border-bottom pb-3 pb-lg-0 aiscan-page-line" data-line-index="${index}">
                <div class="col-sm-4 col-lg-2">
                    <div class="d-lg-none mt-2 small">${escapeHtml(trans('reference'))}</div>
                    <input type="hidden" data-field="selected_reference" value="${escapeAttribute(line.selected_reference)}">
                    <input type="hidden" data-field="product_description" value="${escapeAttribute(line.product_description)}">
                    <div class="input-group input-group-sm mb-1">
                        <input type="text" class="form-control" data-field="reference_search" value="${escapeAttribute(line.selected_reference || line.sku || '')}" placeholder="${escapeAttribute(trans('reference'))}">
                        <button class="btn btn-info aiscan-line-product-search" type="button">
                            <i class="fa-solid fa-book fa-fw"></i>
                        </button>
                    </div>
                    <div class="list-group aiscan-search-results mb-1"></div>
                </div>
                <div class="col-sm-8 col-lg">
                    <div class="d-lg-none mt-2 small">${escapeHtml(trans('description'))}</div>
                    <textarea class="form-control form-control-sm" data-field="description" rows="2">${escapeHtml(line.description)}</textarea>
                </div>
                <div class="col-sm-4 col-lg-1">
                    <div class="d-lg-none mt-2 small">${escapeHtml(trans('quantity'))}</div>
                    <input type="number" class="form-control form-control-sm text-lg-end" data-field="quantity" step="0.01" value="${escapeAttribute(line.quantity)}">
                </div>
                <div class="col-sm-4 col-lg-1">
                    <div class="d-lg-none mt-2 small">${escapeHtml(trans('price'))}</div>
                    <input type="number" class="form-control form-control-sm text-lg-end" data-field="unit_price" step="0.01" value="${escapeAttribute(line.unit_price)}">
                </div>
                <div class="col-sm-4 col-lg-1">
                    <div class="d-lg-none mt-2 small">${escapeHtml(trans('discount'))}</div>
                    <input type="number" class="form-control form-control-sm text-lg-center" data-field="discount" step="0.01" value="${escapeAttribute(line.discount)}">
                </div>
                <div class="col-sm-6 col-lg-1">
                    <div class="d-lg-none mt-2 small">${escapeHtml(trans('tax'))}</div>
                    <input type="number" class="form-control form-control-sm text-lg-end" data-field="tax_rate" step="0.01" value="${escapeAttribute(line.tax_rate)}">
                    <input type="hidden" data-field="sku" value="${escapeAttribute(line.sku)}">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-danger aiscan-delete-line" type="button" title="${escapeAttribute(trans('aiscan-delete-line'))}">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>
            </div>
        `;
    }

    function handleGlobalClick(event) {
        const searchBtn = event.target.closest('#aiscan-supplier-search-btn');
        if (searchBtn) {
            runSupplierSearch();
            return;
        }

        const createSupplierBtn = event.target.closest('#aiscan-create-supplier-btn');
        if (createSupplierBtn) {
            createSupplierFromInline();
            return;
        }

        const supplierResult = event.target.closest('[data-aiscan-supplier-id]');
        if (supplierResult) {
            applyMatchedSupplier({
                id: supplierResult.dataset.aiscanSupplierId,
                name: supplierResult.dataset.aiscanSupplierName,
                tax_id: supplierResult.dataset.aiscanSupplierTaxid || '',
            });
            document.getElementById('aiscan-supplier-results').innerHTML = '';
            return;
        }

        const deleteLine = event.target.closest('.aiscan-delete-line');
        if (deleteLine) {
            const lines = document.querySelectorAll(`#${selectors.linesBody} .aiscan-page-line`);
            if (lines.length > 1) {
                deleteLine.closest('.aiscan-page-line')?.remove();
            }
            return;
        }

        const productSearch = event.target.closest('.aiscan-line-product-search');
        if (productSearch) {
            searchProductsForRow(productSearch.closest('.aiscan-page-line'));
            return;
        }

        const productResult = event.target.closest('[data-aiscan-product-reference]');
        if (productResult) {
            applySelectedProduct(productResult.closest('.aiscan-page-line'), {
                description: productResult.dataset.aiscanProductDescription || '',
                reference: productResult.dataset.aiscanProductReference,
            });
        }
    }

    function handleGlobalInput(event) {
        if (event.target.matches('#aiscan-supplier-name, #aiscan-supplier-taxid')) {
            syncInlineSupplierToDetailModal();
            return;
        }

        if (event.target.matches('[data-field="reference_search"]')) {
            debounceProductSearch(event.target.closest('.aiscan-page-line'));
            return;
        }

        if (event.target.matches('[data-field="description"]') && event.target.value.trim() === '') {
            const row = event.target.closest('.aiscan-page-line');
            const productDescription = row?.querySelector('[data-field="product_description"]')?.value || '';
            if (productDescription) {
                event.target.value = productDescription;
            }
        }
    }

    function handleGlobalKeydown(event) {
        if (event.key === 'Enter' && event.target.matches('[data-field="reference_search"]')) {
            event.preventDefault();
            searchProductsForRow(event.target.closest('.aiscan-page-line'));
        }
    }

    function runSupplierSearch() {
        const query = [readValue('aiscan-supplier-name'), readValue('aiscan-supplier-taxid')]
            .filter(Boolean)
            .join(' ')
            .trim();
        if (query.length < 2) {
            return;
        }

        fetch(`AiScanInvoice?${new URLSearchParams({action: 'search-suppliers', query}).toString()}`)
            .then((response) => response.json())
            .then((data) => {
                const results = Array.isArray(data.results) ? data.results : [];
                const container = document.getElementById('aiscan-supplier-results');
                if (results.length === 0) {
                    container.innerHTML = `<div class="list-group-item small text-muted">${escapeHtml(trans('aiscan-no-results'))}</div>`;
                    return;
                }

                container.innerHTML = results.map((item) => `
                    <button type="button" class="list-group-item list-group-item-action small py-1"
                        data-aiscan-supplier-id="${escapeAttribute(item.id)}"
                        data-aiscan-supplier-name="${escapeAttribute(item.name)}"
                        data-aiscan-supplier-taxid="${escapeAttribute(item.tax_id || '')}">
                        <strong>${escapeHtml(item.name)}</strong>
                        <span class="text-muted ms-1">${escapeHtml(item.tax_id || '')}</span>
                    </button>
                `).join('');
            })
            .catch(() => setStatus(trans('aiscan-no-results'), 'warning'));
    }

    function createSupplierFromInline() {
        const payload = getInlineSupplierData();
        if (!payload.name.trim()) {
            setStatus(trans('aiscan-supplier-name-required'), 'danger');
            return;
        }

        fetch('AiScanInvoice?action=create-supplier', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
        })
            .then((response) => response.json())
            .then((result) => {
                if (result.error) {
                    throw new Error(result.error);
                }

                applyMatchedSupplier(result.supplier);
                setStatus(trans('aiscan-supplier-created'), 'success');
            })
            .catch((error) => setStatus(error.message, 'danger'));
    }

    function getInlineSupplierData() {
        return {
            address: readValue('aiscan-supplier-address'),
            email: readValue('aiscan-supplier-email'),
            name: readValue('aiscan-supplier-name'),
            phone: readValue('aiscan-supplier-phone'),
            tax_id: readValue('aiscan-supplier-taxid'),
        };
    }

    function applyMatchedSupplier(supplier, syncEntry = true) {
        const providerField = getProviderField();
        if (!providerField) {
            return;
        }

        providerField.hidden.value = supplier.id || '';
        if (providerField.text) {
            providerField.text.value = supplier.name || '';
        }
        if (providerField.link) {
            providerField.link.href = `EditProveedor?code=${encodeURIComponent(supplier.id || '')}`;
        }

        setFormValue('input[name="nombre"]', supplier.name || '');
        setFormValue('input[name="cifnif"]', supplier.tax_id || '');
        notifyNativeSupplierSelection(providerField);
        hideSupplierHelper();

        const entry = getCurrentFileEntry();
        if (syncEntry && entry?.extractedData) {
            entry.extractedData.supplier = {
                ...(entry.extractedData.supplier || {}),
                matched_name: supplier.name || '',
                matched_supplier_id: supplier.id || '',
                match_status: 'matched',
                name: supplier.name || entry.extractedData.supplier?.name || '',
                tax_id: supplier.tax_id || entry.extractedData.supplier?.tax_id || '',
            };
            renderDrawerSummary(entry.extractedData);
        }

        if (entry?.extractedData) {
            applyInvoiceToPage(entry.extractedData.invoice || {});
            runNativeSetSupplier();
        }
    }

    function notifyNativeSupplierSelection(providerField) {
        [providerField.hidden, providerField.text].forEach((field) => {
            if (!field) {
                return;
            }

            field.dispatchEvent(new Event('input', {bubbles: true}));
            field.dispatchEvent(new Event('change', {bubbles: true}));
        });

        document.dispatchEvent(new CustomEvent('aiscan:supplier-selected', {
            bubbles: true,
            detail: {
                code: providerField.hidden?.value || '',
                name: providerField.text?.value || '',
            },
        }));
    }

    function runNativeSetSupplier() {
        if (typeof window.purchasesFormAction !== 'function') {
            queuePendingLines();
            return;
        }

        const form = document.forms.purchasesForm;
        if (!form || !form.codproveedor || !form.codproveedor.value) {
            return;
        }

        window.clearTimeout(state.pendingLinesTimer);
        window.purchasesFormAction('set-supplier', '0');
        state.pendingLinesTimer = window.setTimeout(() => {
            const entry = getCurrentFileEntry();
            if (!entry?.extractedData) {
                return;
            }

            applyInvoiceToPage(entry.extractedData.invoice || {});
            waitForInvoiceWorkspace(15);
            window.setTimeout(() => applyInvoiceToPage(entry.extractedData.invoice || {}), 250);
        }, 350);
    }

    function syncInlineSupplierToDetailModal() {
        setFormValue('input[name="nombre"]', readValue('aiscan-supplier-name'));
        setFormValue('input[name="cifnif"]', readValue('aiscan-supplier-taxid'));
    }

    function debounceProductSearch(row) {
        if (!row) {
            return;
        }

        if (row.dataset.searchTimer) {
            window.clearTimeout(Number(row.dataset.searchTimer));
        }

        row.dataset.searchTimer = String(window.setTimeout(() => searchProductsForRow(row), 250));
    }

    function searchProductsForRow(row) {
        if (!row) {
            return;
        }

        const query = row.querySelector('[data-field="reference_search"]')?.value.trim() || '';
        const results = row.querySelector('.aiscan-search-results');
        if (!results) {
            return;
        }

        if (query.length < 2) {
            results.innerHTML = '';
            return;
        }

        fetch(`AiScanInvoice?${new URLSearchParams({action: 'search-products', query}).toString()}`)
            .then((response) => response.json())
            .then((data) => {
                const items = Array.isArray(data.results) ? data.results : [];
                if (items.length === 0) {
                    results.innerHTML = `<div class="list-group-item small text-muted">${escapeHtml(trans('aiscan-no-results'))}</div>`;
                    return;
                }

                results.innerHTML = items.map((item) => `
                    <button type="button" class="list-group-item list-group-item-action small py-1"
                        data-aiscan-product-reference="${escapeAttribute(item.reference || '')}"
                        data-aiscan-product-description="${escapeAttribute(item.description || '')}">
                        <strong>${escapeHtml(item.reference || '')}</strong>
                        <span class="text-muted ms-1">${escapeHtml(item.description || '')}</span>
                    </button>
                `).join('');
            })
            .catch(() => {
                results.innerHTML = '';
            });
    }

    function applySelectedProduct(row, product) {
        if (!row) {
            return;
        }

        const selectedReference = row.querySelector('[data-field="selected_reference"]');
        const referenceSearch = row.querySelector('[data-field="reference_search"]');
        const productDescription = row.querySelector('[data-field="product_description"]');
        const description = row.querySelector('[data-field="description"]');
        const results = row.querySelector('.aiscan-search-results');

        if (selectedReference) {
            selectedReference.value = product.reference || '';
        }
        if (referenceSearch) {
            referenceSearch.value = product.reference || '';
        }
        if (productDescription) {
            productDescription.value = product.description || '';
        }
        if (description && description.value.trim() === '') {
            description.value = product.description || '';
        }
        if (results) {
            results.innerHTML = '';
        }
    }

    function collectPageData(baseData) {
        if (!baseData) {
            return null;
        }

        const data = flow.cloneValue(baseData);
        const warehouseLabel = trans('aiscan-warehouse');
        data.invoice = data.invoice || {};
        data.supplier = data.supplier || {};

        const providerField = getProviderField();
        data.supplier.matched_supplier_id = providerField?.hidden?.value || '';
        data.supplier.matched_name = providerField?.text?.value || '';
        data.supplier.name = readValue('aiscan-supplier-name') || readValueBySelector('input[name="nombre"]') || data.supplier.name || '';
        data.supplier.tax_id = readValue('aiscan-supplier-taxid') || readValueBySelector('input[name="cifnif"]') || data.supplier.tax_id || '';
        data.supplier.email = readValue('aiscan-supplier-email') || data.supplier.email || '';
        data.supplier.phone = readValue('aiscan-supplier-phone') || data.supplier.phone || '';
        data.supplier.address = readValue('aiscan-supplier-address') || data.supplier.address || '';
        data.supplier.match_status = data.supplier.matched_supplier_id ? 'matched' : 'not_found';

        data.invoice.issue_date = readValueBySelector('input[name="fecha"]');
        data.invoice.number = readValueBySelector('input[name="numproveedor"]');
        data.invoice.series = readValueBySelector('select[name="codserie"]');
        data.invoice.payment_method = readValueBySelector('select[name="codpago"]');
        data.invoice.payment_terms = data.invoice.payment_method;
        data.invoice.currency = readValueBySelector('select[name="coddivisa"]') || data.invoice.currency || 'EUR';
        data.invoice.summary = readValueBySelector('textarea[name="observaciones"]');
        data.invoice.warehouse_code = readValue('invoice_warehouse_code') || data.invoice.warehouse_code || '';
        if (!data.invoice.warehouse_code && warehouseLabel === '') {
            data.invoice.warehouse_code = '';
        }

        data.lines = Array.from(document.querySelectorAll(`#${selectors.linesBody} .aiscan-page-line`)).map((row) => {
            const line = {};
            row.querySelectorAll('[data-field]').forEach((input) => {
                if (input.dataset.field === 'reference_search') {
                    return;
                }

                line[input.dataset.field] = input.type === 'number'
                    ? parseFloat(input.value || 0)
                    : input.value;
            });
            return line;
        });

        data._upload = {
            mime_type: getCurrentFileEntry()?.mimeType || '',
            original_name: getCurrentFileEntry()?.originalName || '',
            tmp_file: getCurrentFileEntry()?.tmpFile || '',
        };

        return data;
    }

    function saveCurrentDocument() {
        const entry = getCurrentFileEntry();
        if (!entry || entry.isSaving) {
            return;
        }

        const data = collectPageData(entry.extractedData);
        if (!data) {
            return;
        }

        if (!flow.getSaveInvoiceId(entry) && !data.invoice.warehouse_code) {
            setStatus(trans('aiscan-warehouse-required'), 'danger');
            return;
        }

        entry.extractedData = data;
        entry.isSaving = true;
        updateWizardControls();
        setStatus(trans('aiscan-saving-invoice'), 'info');

        fetch(`AiScanInvoice?${new URLSearchParams({
            action: 'apply',
            invoice_id: flow.getSaveInvoiceId(entry),
        }).toString()}`, {
            body: JSON.stringify(data),
            headers: {'Content-Type': 'application/json'},
            method: 'POST',
        })
            .then((response) => response.json())
            .then((result) => {
                if (result.error) {
                    throw new Error(result.error);
                }

                Object.assign(entry, flow.markEntrySaved(entry, result.invoice_id));
                setStatus(trans('aiscan-invoice-saved-successfully'), 'success');

                const meta = flow.getWizardMeta(state.files, state.currentFileIndex);
                if (meta.isMultiFile && !meta.isLast) {
                    resetNativeInvoiceView();
                    navigateToFile(state.currentFileIndex + 1);
                    return;
                }

                window.setTimeout(() => {
                    window.location.href = `EditFacturaProveedor?code=${encodeURIComponent(result.invoice_id)}&action=save-ok`;
                }, 300);
            })
            .catch((error) => setStatus(error.message, 'danger'))
            .finally(() => {
                entry.isSaving = false;
                updateWizardControls();
            });
    }

    function resetNativeInvoiceView() {
        const providerField = getProviderField();
        if (providerField) {
            providerField.hidden.value = '';
            if (providerField.text) {
                providerField.text.value = '';
            }
        }

        setFormValue('input[name="fecha"]', '');
        setFormValue('input[name="numproveedor"]', '');
        setFormValue('textarea[name="observaciones"]', state.initialObservations || '');
        setFormValue('input[name="nombre"]', '');
        setFormValue('input[name="cifnif"]', '');

        const linesContainer = document.getElementById('purchasesFormLines');
        if (linesContainer) {
            linesContainer.innerHTML = state.initialLinesMarkup || '';
        }

        hideSupplierHelper();
    }

    function resetInvoiceWorkspace() {
        window.clearTimeout(state.pendingLinesTimer);
        const linesContainer = document.getElementById('purchasesFormLines');
        if (linesContainer) {
            linesContainer.innerHTML = state.initialLinesMarkup || '';
        }
    }

    function setFormValue(selector, value) {
        const field = document.querySelector(selector);
        if (field) {
            field.value = value == null ? '' : String(value);
            field.dispatchEvent(new Event('input', {bubbles: true}));
            field.dispatchEvent(new Event('change', {bubbles: true}));
        }
    }

    function setDateValue(selector, value) {
        const field = document.querySelector(selector);
        if (!field) {
            return;
        }

        const normalized = typeof value === 'string' && value.match(/^\d{4}-\d{2}-\d{2}$/)
            ? value
            : '';
        field.value = normalized;
        field.setAttribute('value', normalized);
        field.dispatchEvent(new Event('input', {bubbles: true}));
        field.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function setSelectValue(selector, value) {
        if (!value) {
            return;
        }

        const field = document.querySelector(selector);
        if (!field) {
            return;
        }

        const option = Array.from(field.options).find((item) => item.value === value);
        if (option) {
            field.value = value;
        }
    }

    function readValue(id) {
        return document.getElementById(id)?.value || '';
    }

    function readValueBySelector(selector) {
        return document.querySelector(selector)?.value || '';
    }

    function setValue(id, value) {
        const field = document.getElementById(id);
        if (field) {
            field.value = value == null ? '' : String(value);
        }
    }

    function setStatus(message, level = '') {
        const status = document.getElementById(selectors.status);
        if (!status) {
            return;
        }

        if (!message) {
            status.innerHTML = '';
            return;
        }

        status.innerHTML = `<div class="alert alert-${level || 'secondary'} py-2 px-2 small mb-0">${escapeHtml(message)}</div>`;
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
