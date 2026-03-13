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
        invoiceId: null,
        mimeType: null,
        originalName: null,
        tmpFile: null,
        objectUrl: null,
        availableProviders: [],
        defaultProvider: null,
        browserPromptSupported: false,
        extractionPrompt: null,
    };

    const selectors = {
        modalId: 'modalaiscan',
        acceptBtn: 'aiscan-accept-btn',
        fileInput: 'aiscan-file-input',
        linesBody: 'aiscan-lines-body',
        previewArea: 'aiscan-preview-area',
        providerSelect: 'aiscan-provider-select',
        review: 'aiscan-review',
        scanBtn: 'aiscan-scan-btn',
        status: 'aiscan-status',
        clearBtn: 'aiscan-clear-btn',
        toolbar: 'aiscan-toolbar',
    };

    // Fallback prompt for Browser AI when server prompt is not available
    const FALLBACK_PROMPT = 'You are an invoice extraction engine. Extract data from the provided invoice and return ONLY valid JSON with supplier, invoice, taxes, lines, confidence and warnings fields. Never invent values, use null for unknown fields.';

    const FALLBACK_TRANSLATIONS = {
        en: {
            'aiscan-analyze': 'Analyze',
            'aiscan-analysis-completed': 'Analysis completed (%provider%).',
            'aiscan-analysis-started': 'Analyzing invoice with %provider%...',
            'aiscan-analyzing-browser-ai': 'Analyzing with Browser AI (this may take a moment)...',
            'aiscan-browser-ai-error': 'Browser AI error: %message%',
            'aiscan-browser-prompt-not-available': 'Browser Prompt API is not available in this browser.',
            'aiscan-clear-file': 'Remove file',
            'aiscan-create-new-supplier-confirm': 'Supplier not found. Do you want AiScan to create a new supplier with the extracted data?',
            'aiscan-create-or-update-invoice': 'Create / update invoice',
            'aiscan-delete-line': 'Delete line',
            'aiscan-document': 'Document',
            'aiscan-document-and-image-drop': 'Drop a PDF or image here',
            'aiscan-document-preview': 'Invoice preview',
            'aiscan-document-type': 'Document type',
            'aiscan-drag-to-resize': 'Drag to resize',
            'aiscan-drop-or-click': 'or click to select a file',
            'aiscan-file-uploaded-select-provider': 'File uploaded. Select a provider and click Analyze.',
            'aiscan-initial-review-message': 'Upload a document and analyze it to review supplier, invoice and line data.',
            'aiscan-invoice-saved-successfully': 'Invoice saved successfully.',
            'aiscan-line-items': 'Line items',
            'aiscan-loading-provider': 'Loading...',
            'aiscan-matched-with': 'Matched with: %name%',
            'aiscan-no-results': 'No results',
            'aiscan-provider-browser-prompt': 'Browser Prompt API (experimental)',
            'aiscan-provider-gemini': 'Google Gemini',
            'aiscan-provider-label-browser-prompt': 'Browser AI',
            'aiscan-provider-mistral': 'Mistral',
            'aiscan-provider-openai': 'OpenAI',
            'aiscan-provider-openai-compatible': 'OpenAI compatible',
            'aiscan-review-extracted-data': 'Review extracted data',
            'aiscan-saving-invoice': 'Saving purchase invoice...',
            'aiscan-scanned-supplier-invoice': 'Scanned supplier invoice',
            'aiscan-selected-supplier': 'Selected: %name% (%taxId%)',
            'aiscan-supplier-ambiguous': 'Multiple supplier matches found. Select the correct supplier.',
            'aiscan-supplier-created-on-save': 'A new supplier will be created when you save the invoice.',
            'aiscan-supplier-not-found-on-save': 'No supplier match found. You can create one during save.',
            'aiscan-uploading-file': 'Uploading file...',
            'aiscan-use-cloud-provider-for-image': 'Browser AI cannot analyze images. Please select a cloud provider (OpenAI, Gemini, etc.).',
            'aiscan-use-cloud-provider-for-pdf': 'Could not extract text from this PDF. Please select a cloud provider for analysis.',
            'aiscan-validation-warnings': 'Warnings',
            'confidence': 'Confidence',
            'scan-invoice': 'Scan Invoice',
        },
        es: {
            'aiscan-analyze': 'Analizar',
            'aiscan-analysis-completed': 'Analisis completado (%provider%).',
            'aiscan-analysis-started': 'Analizando factura con %provider%...',
            'aiscan-analyzing-browser-ai': 'Analizando con Browser AI (esto puede tardar un momento)...',
            'aiscan-browser-ai-error': 'Error de Browser AI: %message%',
            'aiscan-browser-prompt-not-available': 'La API Browser Prompt no esta disponible en este navegador.',
            'aiscan-clear-file': 'Quitar archivo',
            'aiscan-create-new-supplier-confirm': 'No se ha encontrado el proveedor. Quieres que AiScan cree uno nuevo con los datos extraidos?',
            'aiscan-create-or-update-invoice': 'Crear / actualizar factura',
            'aiscan-delete-line': 'Eliminar linea',
            'aiscan-document': 'Documento',
            'aiscan-document-and-image-drop': 'Suelta aqui un PDF o una imagen',
            'aiscan-document-preview': 'Vista previa de la factura',
            'aiscan-document-type': 'Tipo de documento',
            'aiscan-drag-to-resize': 'Arrastra para redimensionar',
            'aiscan-drop-or-click': 'o haz clic para seleccionar un archivo',
            'aiscan-file-uploaded-select-provider': 'Archivo subido. Selecciona un proveedor y pulsa Analizar.',
            'aiscan-initial-review-message': 'Sube un documento y analizalo para revisar proveedor, factura y lineas.',
            'aiscan-invoice-saved-successfully': 'Factura guardada correctamente.',
            'aiscan-line-items': 'Lineas',
            'aiscan-loading-provider': 'Cargando...',
            'aiscan-matched-with': 'Coincide con: %name%',
            'aiscan-no-results': 'Sin resultados',
            'aiscan-provider-browser-prompt': 'Browser Prompt API (experimental)',
            'aiscan-provider-gemini': 'Google Gemini',
            'aiscan-provider-label-browser-prompt': 'Browser AI',
            'aiscan-provider-mistral': 'Mistral',
            'aiscan-provider-openai': 'OpenAI',
            'aiscan-provider-openai-compatible': 'OpenAI compatible',
            'aiscan-review-extracted-data': 'Revisar datos extraidos',
            'aiscan-saving-invoice': 'Guardando factura de proveedor...',
            'aiscan-scanned-supplier-invoice': 'Factura de proveedor escaneada',
            'aiscan-selected-supplier': 'Seleccionado: %name% (%taxId%)',
            'aiscan-supplier-ambiguous': 'Se han encontrado varios proveedores coincidentes. Selecciona el correcto.',
            'aiscan-supplier-created-on-save': 'Se creara un nuevo proveedor al guardar la factura.',
            'aiscan-supplier-not-found-on-save': 'No se ha encontrado coincidencia de proveedor. Puedes crear uno al guardar.',
            'aiscan-uploading-file': 'Subiendo archivo...',
            'aiscan-use-cloud-provider-for-image': 'Browser AI no puede analizar imagenes. Selecciona un proveedor en la nube (OpenAI, Gemini, etc.).',
            'aiscan-use-cloud-provider-for-pdf': 'No se pudo extraer texto de este PDF. Selecciona un proveedor en la nube para analizarlo.',
            'aiscan-validation-warnings': 'Avisos',
            'confidence': 'Confianza',
            'scan-invoice': 'Escanear Factura',
        },
    };

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

    function setInitialReviewMessage() {
        const review = document.getElementById(selectors.review);
        if (!review) {
            return;
        }

        review.innerHTML = `<p class="text-muted mb-0">${escapeHtml(trans('aiscan-initial-review-message'))}</p>`;
    }

    document.addEventListener('DOMContentLoaded', () => {
        checkBrowserPromptSupport();
        ensureModal();
        bindModalEvents();
    });

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

    function ensureModal() {
        if (document.getElementById(selectors.modalId)) {
            return;
        }

        document.body.insertAdjacentHTML('beforeend', `
            <div class="modal fade aiscan-modal" id="${selectors.modalId}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fa-solid fa-file-invoice me-2"></i>${escapeHtml(trans('scan-invoice'))}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="${escapeAttribute(trans('close'))}"></button>
                        </div>
                        <div class="modal-body p-0">
                            <div class="aiscan-split" id="aiscan-split">
                                <div class="aiscan-split-left" id="aiscan-split-left">
                                    <div class="card h-100 border-0 rounded-0">
                                        <div class="card-header d-flex justify-content-between align-items-center py-2 rounded-0">
                                            <span>${escapeHtml(trans('aiscan-document'))}</span>
                                            <div class="d-flex align-items-center gap-2" id="${selectors.toolbar}" style="display:none!important">
                                                <select class="form-select form-select-sm" id="${selectors.providerSelect}" style="width:auto;min-width:140px"></select>
                                                <button type="button" class="btn btn-primary btn-sm text-nowrap" id="${selectors.scanBtn}" disabled>
                                                    <i class="fa-solid fa-wand-magic-sparkles me-1"></i>${escapeHtml(trans('aiscan-analyze'))}
                                                </button>
                                            </div>
                                        </div>
                                        <div class="card-body p-0 position-relative">
                                            <input id="${selectors.fileInput}" type="file" class="d-none" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp">
                                            <div class="aiscan-preview-area" id="${selectors.previewArea}">
                                                ${dropAreaHtml()}
                                            </div>
                                            <button type="button" class="btn btn-sm btn-light position-absolute top-0 end-0 m-2 rounded-circle shadow-sm d-none" id="${selectors.clearBtn}" title="${escapeAttribute(trans('aiscan-clear-file'))}" style="z-index:2;width:32px;height:32px;padding:0">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </div>
                                        <div class="card-footer py-2 rounded-0">
                                            <div id="${selectors.status}" class="small"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="aiscan-split-handle" id="aiscan-split-handle" title="${escapeAttribute(trans('aiscan-drag-to-resize'))}"><div class="aiscan-split-handle-grip"></div></div>
                                <div class="aiscan-split-right" id="aiscan-split-right">
                                    <div class="card h-100 border-0 rounded-0">
                                        <div class="card-header py-2 rounded-0">${escapeHtml(trans('aiscan-review-extracted-data'))}</div>
                                        <div class="card-body aiscan-sidebar" id="${selectors.review}">
                                            <p class="text-muted mb-0">${escapeHtml(trans('aiscan-initial-review-message'))}</p>
                                        </div>
                                        <div class="card-footer text-end py-2 rounded-0">
                                            <button type="button" class="btn btn-success btn-sm" id="${selectors.acceptBtn}" disabled>
                                                <i class="fa-solid fa-check me-1"></i>${escapeHtml(trans('aiscan-create-or-update-invoice'))}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `);
    }

    function buildProviderSelect() {
        const select = document.getElementById(selectors.providerSelect);
        if (!select) {
            return;
        }
        select.innerHTML = '';

        const providers = state.availableProviders || [];
        providers.forEach((p) => {
            const opt = document.createElement('option');
            opt.value = p;
            opt.textContent = providerLabel(p);
            if (p === state.defaultProvider) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });

        if (state.browserPromptSupported) {
            const opt = document.createElement('option');
            opt.value = 'browser-prompt';
            opt.textContent = providerLabel('browser-prompt');
            if (state.defaultProvider === 'browser-prompt') {
                opt.selected = true;
            }
            select.appendChild(opt);
        }
    }

    function bindModalEvents() {
        const modal = document.getElementById(selectors.modalId);
        const previewArea = document.getElementById(selectors.previewArea);
        const dropZone = document.getElementById('aiscan-drop-zone');
        const fileInput = document.getElementById(selectors.fileInput);
        const scanBtn = document.getElementById(selectors.scanBtn);
        const acceptBtn = document.getElementById(selectors.acceptBtn);
        const clearBtn = document.getElementById(selectors.clearBtn);

        modal.addEventListener('show.bs.modal', () => resetModal());

        previewArea.addEventListener('dragover', (event) => {
            event.preventDefault();
            const dz = document.getElementById('aiscan-drop-zone');
            if (dz) {
                dz.classList.add('aiscan-drag-over');
            }
        });

        previewArea.addEventListener('dragleave', (event) => {
            const dz = document.getElementById('aiscan-drop-zone');
            if (dz && !previewArea.contains(event.relatedTarget)) {
                dz.classList.remove('aiscan-drag-over');
            }
        });

        previewArea.addEventListener('drop', (event) => {
            event.preventDefault();
            const dz = document.getElementById('aiscan-drop-zone');
            if (dz) {
                dz.classList.remove('aiscan-drag-over');
            }
            if (event.dataTransfer.files.length > 0) {
                handleFile(event.dataTransfer.files[0]);
            }
        });

        previewArea.addEventListener('click', (event) => {
            if (document.getElementById('aiscan-drop-zone') && !event.target.closest('button')) {
                fileInput.click();
            }
        });

        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                handleFile(fileInput.files[0]);
            }
        });

        clearBtn.addEventListener('click', (event) => {
            event.stopPropagation();
            clearFile();
        });

        scanBtn.addEventListener('click', () => {
            const selected = document.getElementById(selectors.providerSelect)?.value || '';
            if (selected === 'browser-prompt') {
                analyzeWithBrowserPrompt();
            } else {
                analyzeDocument(selected || undefined);
            }
        });

        acceptBtn.addEventListener('click', acceptExtracted);

        bindSplitHandle();
    }

    function bindSplitHandle() {
        const handle = document.getElementById('aiscan-split-handle');
        const split = document.getElementById('aiscan-split');
        const left = document.getElementById('aiscan-split-left');
        if (!handle || !split || !left) {
            return;
        }

        let dragging = false;

        handle.addEventListener('mousedown', (e) => {
            e.preventDefault();
            dragging = true;
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
            split.classList.add('aiscan-split-dragging');
        });

        document.addEventListener('mousemove', (e) => {
            if (!dragging) {
                return;
            }
            const rect = split.getBoundingClientRect();
            const offset = e.clientX - rect.left;
            const pct = Math.min(Math.max((offset / rect.width) * 100, 25), 75);
            left.style.flexBasis = pct + '%';
        });

        document.addEventListener('mouseup', () => {
            if (dragging) {
                dragging = false;
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                split.classList.remove('aiscan-split-dragging');
            }
        });
    }

    function clearFile() {
        if (state.objectUrl) {
            URL.revokeObjectURL(state.objectUrl);
        }
        state.mimeType = null;
        state.originalName = null;
        state.tmpFile = null;
        state.objectUrl = null;

        const previewArea = document.getElementById(selectors.previewArea);
        previewArea.innerHTML = dropAreaHtml();

        document.getElementById(selectors.clearBtn).classList.add('d-none');
        document.getElementById(selectors.scanBtn).disabled = true;
        document.getElementById(selectors.fileInput).value = '';
        setStatus('');
    }

    function resetModal() {
        if (state.objectUrl) {
            URL.revokeObjectURL(state.objectUrl);
        }

        state.invoiceId = getInvoiceId();
        state.mimeType = null;
        state.originalName = null;
        state.tmpFile = null;
        state.objectUrl = null;

        const previewArea = document.getElementById(selectors.previewArea);
        previewArea.innerHTML = dropAreaHtml();

        document.getElementById(selectors.fileInput).value = '';
        document.getElementById(selectors.clearBtn).classList.add('d-none');
        document.getElementById(selectors.scanBtn).disabled = true;
        document.getElementById(selectors.toolbar).style.display = 'none';
        document.getElementById(selectors.acceptBtn).disabled = true;
        document.getElementById(selectors.acceptBtn).dataset.extractedData = '';
        setInitialReviewMessage();
        const splitLeft = document.getElementById('aiscan-split-left');
        if (splitLeft) {
            splitLeft.style.flexBasis = '';
        }
        setStatus('');
    }

    function getInvoiceId() {
        const params = new URLSearchParams(window.location.search);
        return params.get('code') || document.querySelector('input[name="code"]')?.value || '';
    }

    function handleFile(file) {
        showPreview(file);
        uploadFile(file);
    }

    function showPreview(file) {
        if (state.objectUrl) {
            URL.revokeObjectURL(state.objectUrl);
        }

        state.objectUrl = URL.createObjectURL(file);
        const previewArea = document.getElementById(selectors.previewArea);
        previewArea.innerHTML = '';

        if (file.type === 'application/pdf') {
            previewArea.innerHTML = `<iframe src="${state.objectUrl}" title="${escapeAttribute(trans('aiscan-document-preview'))}"></iframe>`;
        } else {
            previewArea.innerHTML = `<img src="${state.objectUrl}" alt="${escapeAttribute(trans('aiscan-document-preview'))}">`;
        }

        document.getElementById(selectors.clearBtn).classList.remove('d-none');
    }

    function uploadFile(file) {
        const formData = new FormData();
        formData.append('invoice_file', file);
        setStatus(trans('aiscan-uploading-file'), 'info');

        fetch('AiScanInvoice?action=upload', {
            method: 'POST',
            body: formData,
        })
            .then((response) => response.json())
            .then((data) => {
                if (data.error) {
                    throw new Error(data.error);
                }

                state.tmpFile = data.tmp_file;
                state.mimeType = data.mime_type;
                state.originalName = data.original_name;
                state.defaultProvider = data.provider || 'unknown';
                state.availableProviders = data.available_providers || [data.provider || 'unknown'];
                state.extractionPrompt = data.extraction_prompt || FALLBACK_PROMPT;

                document.getElementById(selectors.toolbar).style.display = '';
                document.getElementById(selectors.scanBtn).disabled = false;
                buildProviderSelect();
                setStatus(trans('aiscan-file-uploaded-select-provider'), 'success');

                if (data.auto_scan) {
                    const selected = document.getElementById(selectors.providerSelect)?.value || '';
                    if (selected === 'browser-prompt') {
                        analyzeWithBrowserPrompt();
                    } else {
                        analyzeDocument(selected || undefined);
                    }
                }
            })
            .catch((error) => setStatus(error.message, 'danger'));
    }

    function analyzeDocument(provider) {
        if (!state.tmpFile) {
            return;
        }

        const providerName = providerLabel(provider || state.defaultProvider);
        setStatus(trans('aiscan-analysis-started', {'%provider%': providerName}), 'info');
        document.getElementById(selectors.scanBtn).disabled = true;

        const params = new URLSearchParams({
            action: 'analyze',
            tmp_file: state.tmpFile,
            mime_type: state.mimeType || '',
        });
        if (provider) {
            params.set('provider', provider);
        }

        fetch('AiScanInvoice?' + params.toString())
            .then((response) => response.json())
            .then((data) => {
                if (data.error) {
                    throw new Error(data.error);
                }

                renderReviewForm(data.data);
                setStatus(
                    trans('aiscan-analysis-completed', {
                        '%provider%': providerLabel(data.data._provider || provider || state.defaultProvider),
                    }),
                    'success'
                );
            })
            .catch((error) => setStatus(error.message, 'danger'))
            .finally(() => {
                document.getElementById(selectors.scanBtn).disabled = false;
            });
    }

    async function analyzeWithBrowserPrompt() {
        if (typeof LanguageModel === 'undefined') {
            setStatus(trans('aiscan-browser-prompt-not-available'), 'danger');
            return;
        }

        setStatus(trans('aiscan-analyzing-browser-ai'), 'info');
        document.getElementById(selectors.scanBtn).disabled = true;

        try {
            const session = await LanguageModel.create({
                expectedInputs: [{type: 'text', languages: ['en', 'es']}],
                expectedOutputs: [{type: 'text', languages: ['en']}],
                initialPrompts: [
                    {role: 'system', content: 'You are an expert invoice data extractor. You always respond with valid JSON only, no markdown.'},
                ],
            });

            const textContent = await fetchDocumentText();
            if (!textContent) {
                const isImage = state.mimeType && state.mimeType.startsWith('image/');
                const msg = isImage
                    ? trans('aiscan-use-cloud-provider-for-image')
                    : trans('aiscan-use-cloud-provider-for-pdf');
                setStatus(msg, 'danger');
                return;
            }

            const prompt = state.extractionPrompt || FALLBACK_PROMPT;
            const rawJson = await session.prompt(prompt + '\n\nDocument content:\n' + textContent);
            session.destroy();

            let cleaned = rawJson.replace(/^```(?:json)?\n?/m, '').replace(/\n?```$/m, '').trim();
            const data = JSON.parse(cleaned);

            data._provider = 'browser-prompt';
            data._validation_errors = [];

            renderReviewForm(data);
            setStatus(trans('aiscan-analysis-completed', {'%provider%': providerLabel('browser-prompt')}), 'success');
        } catch (error) {
            setStatus(trans('aiscan-browser-ai-error', {'%message%': error.message}), 'danger');
        } finally {
            document.getElementById(selectors.scanBtn).disabled = false;
        }
    }

    async function fetchDocumentText() {
        if (!state.tmpFile) {
            return null;
        }

        // Browser AI is text-only — images cannot be analyzed
        if (state.mimeType && state.mimeType.startsWith('image/')) {
            return null;
        }

        // Try server-side extraction first (pdftotext)
        try {
            const params = new URLSearchParams({
                action: 'get-text',
                tmp_file: state.tmpFile,
            });
            const response = await fetch('AiScanInvoice?' + params.toString());
            const data = await response.json();
            if (data.text && data.text.trim().length > 10) {
                return data.text;
            }
        } catch (error) {
            // fall through to client-side extraction
        }

        // Fallback: client-side PDF text extraction via pdf.js
        if (state.mimeType === 'application/pdf' && state.objectUrl) {
            return await extractPdfTextClientSide(state.objectUrl);
        }

        return null;
    }

    async function extractPdfTextClientSide(url) {
        try {
            const PDFJS_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.4.168';
            if (!window._pdfjsLib) {
                window._pdfjsLib = await import(PDFJS_CDN + '/pdf.min.mjs');
                window._pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_CDN + '/pdf.worker.min.mjs';
            }
            const pdfjsLib = window._pdfjsLib;

            const pdf = await pdfjsLib.getDocument(url).promise;
            const pages = [];
            for (let i = 1; i <= pdf.numPages; i++) {
                const page = await pdf.getPage(i);
                const content = await page.getTextContent();
                pages.push(content.items.map((item) => item.str).join(' '));
            }
            const text = pages.join('\n').trim();
            return text.length > 10 ? text : null;
        } catch (error) {
            return null;
        }
    }

    function renderReviewForm(data) {
        const review = document.getElementById(selectors.review);
        const invoice = data.invoice || {};
        const supplier = data.supplier || {};
        const taxes = Array.isArray(data.taxes) ? data.taxes : [];
        const lines = Array.isArray(data.lines) ? data.lines : [];
        const validationErrors = Array.isArray(data._validation_errors) ? data._validation_errors : [];
        const confidence = data.confidence || {};

        review.innerHTML = '';

        if (validationErrors.length > 0) {
            review.insertAdjacentHTML('beforeend', `
                <div class="alert alert-warning py-2">
                    <strong class="small">${escapeHtml(trans('aiscan-validation-warnings'))}</strong>
                    <ul class="mb-0 small">${validationErrors.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>
                </div>
            `);
        }

        if (data.document_type && data.document_type !== 'invoice') {
            review.insertAdjacentHTML('beforeend', `
                <div class="alert alert-info py-2 small">
                    <i class="fa-solid fa-circle-info me-1"></i>${escapeHtml(trans('aiscan-document-type'))}: <strong>${escapeHtml(data.document_type)}</strong>
                </div>
            `);
        }

        review.appendChild(buildSection(trans('supplier'), `
            ${buildInput(trans('name'), 'supplier_name', supplier.name || '', 'text', null, confidence.supplier_name)}
            ${buildInput(trans('tax-id'), 'supplier_tax_id', supplier.tax_id || '', 'text', null, confidence.supplier_tax_id)}
            ${buildInput(trans('email'), 'supplier_email', supplier.email || '')}
            ${buildInput(trans('phone'), 'supplier_phone', supplier.phone || '')}
            ${buildTextarea(trans('address'), 'supplier_address', supplier.address || '')}
            ${buildSupplierStatus(supplier)}
        `));

        review.appendChild(buildSection(trans('invoice'), `
            ${buildInput(trans('number'), 'invoice_number', invoice.number || '', 'text', null, confidence.invoice_number)}
            ${buildInput(trans('date'), 'invoice_issue_date', invoice.issue_date || '', 'date', null, confidence.issue_date)}
            ${buildInput(trans('expiration'), 'invoice_due_date', invoice.due_date || '', 'date', null, confidence.due_date)}
            ${buildInput(trans('currency'), 'invoice_currency', invoice.currency || 'EUR')}
            ${buildInput(trans('subtotal'), 'invoice_subtotal', invoice.subtotal ?? '', 'number', '0.01')}
            ${buildInput(trans('tax-amount'), 'invoice_tax_amount', invoice.tax_amount ?? '', 'number', '0.01')}
            ${invoice.withholding_amount ? buildInput(trans('irpf'), 'invoice_withholding', invoice.withholding_amount, 'number', '0.01') : ''}
            ${buildInput(trans('total'), 'invoice_total', invoice.total ?? '', 'number', '0.01', confidence.total)}
            ${buildTextarea(trans('summary'), 'invoice_summary', invoice.summary || '')}
            ${invoice.payment_terms ? buildInput(trans('payment-terms'), 'invoice_payment_terms', invoice.payment_terms) : ''}
        `));

        if (taxes.length > 0) {
            review.appendChild(buildSection(trans('taxes'), `
                <div class="small">
                    ${taxes.map((tax) => `<strong>${escapeHtml(tax.name || trans('tax'))}</strong>: ${escapeHtml(tax.rate || 0)}% - ${escapeHtml(trans('base-imponible'))} ${escapeHtml(tax.base || 0)} - ${escapeHtml(trans('amount'))} ${escapeHtml(tax.amount || 0)}`).join('<br>')}
                </div>
            `));
        }

        review.appendChild(buildLinesSection(lines));

        bindSupplierSearch();

        document.getElementById(selectors.acceptBtn).disabled = false;
        document.getElementById(selectors.acceptBtn).dataset.extractedData = JSON.stringify(data);
    }

    function buildSection(title, bodyHtml) {
        const section = document.createElement('div');
        section.className = 'card mb-3';
        section.innerHTML = `
            <div class="card-header py-2">${escapeHtml(title)}</div>
            <div class="card-body py-2">${bodyHtml}</div>
        `;
        return section;
    }

    function buildInput(label, id, value, type = 'text', step = null, confidence = null) {
        const badge = confidence != null ? ` <span class="badge ${confidence >= 0.7 ? 'text-bg-success' : confidence >= 0.4 ? 'text-bg-warning' : 'text-bg-danger'}" title="${escapeAttribute(trans('confidence'))}">${Math.round(confidence * 100)}%</span>` : '';
        return `
            <div class="mb-2">
                <label class="form-label small mb-1" for="${id}">${escapeHtml(label)}${badge}</label>
                <input class="form-control form-control-sm" id="${id}" type="${type}" ${step ? `step="${step}"` : ''} value="${escapeAttribute(value)}">
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
                        ${supplier.candidates.map((candidate) => `<option value="${escapeAttribute(candidate.id)}">${escapeHtml(candidate.name)} (${escapeHtml(candidate.tax_id || '')})</option>`).join('')}
                    </select>
                </div>
            `;
        }

        const searchBox = `
            <div class="mt-2">
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" id="aiscan-supplier-search" placeholder="${escapeAttribute(trans('search'))}">
                    <button class="btn btn-outline-secondary" type="button" id="aiscan-supplier-search-btn"><i class="fa-solid fa-search"></i></button>
                </div>
                <div id="aiscan-supplier-results" class="list-group mt-1" style="max-height:150px;overflow-y:auto"></div>
            </div>
        `;

        return `<div class="alert alert-${variant.klass} small mb-0">${escapeHtml(variant.text)}${select}</div>${searchBox}`;
    }

    function bindSupplierSearch() {
        const searchInput = document.getElementById('aiscan-supplier-search');
        const searchBtn = document.getElementById('aiscan-supplier-search-btn');
        const resultsDiv = document.getElementById('aiscan-supplier-results');
        if (!searchInput || !searchBtn || !resultsDiv) {
            return;
        }

        let debounceTimer = null;

        const doSearch = () => {
            const query = searchInput.value.trim();
            if (query.length < 2) {
                resultsDiv.innerHTML = '';
                return;
            }
            const params = new URLSearchParams({action: 'search-suppliers', query});
            fetch('AiScanInvoice?' + params.toString())
                .then((r) => r.json())
                .then((data) => {
                    const items = data.results || [];
                    if (items.length === 0) {
                        resultsDiv.innerHTML = `<div class="list-group-item small text-muted">${escapeHtml(trans('aiscan-no-results'))}</div>`;
                        return;
                    }
                    resultsDiv.innerHTML = items.map((s) =>
                        `<button type="button" class="list-group-item list-group-item-action small py-1" data-id="${escapeAttribute(s.id)}" data-name="${escapeAttribute(s.name)}" data-taxid="${escapeAttribute(s.tax_id || '')}">
                            <strong>${escapeHtml(s.name)}</strong> <span class="text-muted">${escapeHtml(s.tax_id || '')}</span>
                        </button>`
                    ).join('');
                })
                .catch(() => {
                    resultsDiv.innerHTML = '';
                });
        };

        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(doSearch, 300);
        });

        searchBtn.addEventListener('click', doSearch);

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                doSearch();
            }
        });

        resultsDiv.addEventListener('click', (event) => {
            const item = event.target.closest('[data-id]');
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

            // Set or create the hidden select for matched supplier ID
            let matchSelect = document.getElementById('supplier_match_select');
            if (!matchSelect) {
                matchSelect = document.createElement('select');
                matchSelect.id = 'supplier_match_select';
                matchSelect.className = 'form-select form-select-sm d-none';
                resultsDiv.parentElement.appendChild(matchSelect);
            }
            matchSelect.innerHTML = `<option value="${escapeAttribute(item.dataset.id)}" selected>${escapeHtml(item.dataset.name)}</option>`;

            resultsDiv.innerHTML = '';
            searchInput.value = '';

            // Update status alert
            const alertEl = resultsDiv.closest('.card-body')?.querySelector('.alert');
            if (alertEl) {
                alertEl.className = 'alert alert-success small mb-0';
                alertEl.textContent = trans('aiscan-selected-supplier', {
                    '%name%': item.dataset.name,
                    '%taxId%': item.dataset.taxid,
                });
            }
        });
    }

    function buildLinesSection(lines) {
        const rows = (lines.length > 0 ? lines : [{
            description: trans('aiscan-scanned-supplier-invoice'),
            discount: 0,
            quantity: 1,
            tax_rate: 0,
            unit_price: 0,
        }]).map((line, index) => `
            <tr data-line-index="${index}">
                <td><input class="form-control form-control-sm" data-field="description" value="${escapeAttribute(line.description || '')}"></td>
                <td><input class="form-control form-control-sm" data-field="quantity" type="number" step="0.01" value="${escapeAttribute(line.quantity ?? 1)}" style="width:70px"></td>
                <td><input class="form-control form-control-sm" data-field="unit_price" type="number" step="0.01" value="${escapeAttribute(line.unit_price ?? 0)}" style="width:90px"></td>
                <td><input class="form-control form-control-sm" data-field="discount" type="number" step="0.01" value="${escapeAttribute(line.discount ?? 0)}" style="width:70px"></td>
                <td><input class="form-control form-control-sm" data-field="tax_rate" type="number" step="0.01" value="${escapeAttribute(line.tax_rate ?? 0)}" style="width:70px"></td>
                <td><input class="form-control form-control-sm" data-field="sku" value="${escapeAttribute(line.sku || '')}" style="width:80px"></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger aiscan-delete-line" title="${escapeAttribute(trans('aiscan-delete-line'))}"><i class="fa-solid fa-trash-can"></i></button></td>
            </tr>
        `).join('');

        const section = buildSection(trans('aiscan-line-items'), `
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>${escapeHtml(trans('description'))}</th>
                            <th>${escapeHtml(trans('quantity'))}</th>
                            <th>${escapeHtml(trans('price'))}</th>
                            <th>${escapeHtml(trans('discount'))} %</th>
                            <th>${escapeHtml(trans('tax'))} %</th>
                            <th>SKU</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="${selectors.linesBody}">${rows}</tbody>
                </table>
            </div>
        `);

        section.addEventListener('click', (event) => {
            const btn = event.target.closest('.aiscan-delete-line');
            if (btn) {
                const row = btn.closest('tr');
                if (row && document.querySelectorAll(`#${selectors.linesBody} tr`).length > 1) {
                    row.remove();
                }
            }
        });

        return section;
    }

    function acceptExtracted() {
        const acceptBtn = document.getElementById(selectors.acceptBtn);
        if (!acceptBtn.dataset.extractedData) {
            return;
        }

        const data = JSON.parse(acceptBtn.dataset.extractedData);
        data.invoice = data.invoice || {};
        data.supplier = data.supplier || {};

        data.invoice.number = readValue('invoice_number');
        data.invoice.issue_date = readValue('invoice_issue_date');
        data.invoice.due_date = readValue('invoice_due_date');
        data.invoice.currency = readValue('invoice_currency');
        data.invoice.subtotal = parseFloat(readValue('invoice_subtotal') || 0);
        data.invoice.tax_amount = parseFloat(readValue('invoice_tax_amount') || 0);
        data.invoice.total = parseFloat(readValue('invoice_total') || 0);
        const withholdingEl = document.getElementById('invoice_withholding');
        if (withholdingEl) {
            data.invoice.withholding_amount = parseFloat(withholdingEl.value || 0);
        }
        data.invoice.summary = readValue('invoice_summary');
        const paymentTermsEl = document.getElementById('invoice_payment_terms');
        if (paymentTermsEl) {
            data.invoice.payment_terms = paymentTermsEl.value;
        }

        data.supplier.name = readValue('supplier_name');
        data.supplier.tax_id = readValue('supplier_tax_id');
        data.supplier.email = readValue('supplier_email');
        data.supplier.phone = readValue('supplier_phone');
        data.supplier.address = readValue('supplier_address');

        const selectedSupplier = document.getElementById('supplier_match_select');
        if (selectedSupplier) {
            data.supplier.matched_supplier_id = selectedSupplier.value;
        } else if ((data.supplier.match_status || 'not_found') === 'not_found') {
            if (!window.confirm(trans('aiscan-create-new-supplier-confirm'))) {
                return;
            }
            data.supplier.create_if_missing = true;
        }

        data.lines = Array.from(document.querySelectorAll(`#${selectors.linesBody} tr`)).map((row) => {
            const line = {};
            row.querySelectorAll('[data-field]').forEach((input) => {
                line[input.dataset.field] = input.type === 'number' ? parseFloat(input.value || 0) : input.value;
            });
            return line;
        });

        data._upload = {
            mime_type: state.mimeType,
            original_name: state.originalName,
            tmp_file: state.tmpFile,
        };

        setStatus(trans('aiscan-saving-invoice'), 'info');

        const params = new URLSearchParams({
            action: 'apply',
            invoice_id: state.invoiceId || '',
        });

        fetch('AiScanInvoice?' + params.toString(), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data),
        })
            .then((response) => response.json())
            .then((result) => {
                if (result.error) {
                    throw new Error(result.error);
                }

                setStatus(trans('aiscan-invoice-saved-successfully'), 'success');
                bootstrap.Modal.getInstance(document.getElementById(selectors.modalId))?.hide();
                setTimeout(() => window.location.href = 'EditFacturaProveedor?code=' + encodeURIComponent(result.invoice_id), 300);
            })
            .catch((error) => setStatus(error.message, 'danger'));
    }

    function readValue(id) {
        return document.getElementById(id)?.value || '';
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

        status.innerHTML = `<div class="alert alert-${level || 'secondary'} py-1 px-2 small mb-0">${escapeHtml(message)}</div>`;
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
