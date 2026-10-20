    // ── UI Navigation ──────────────────────────────────────────────────

    function showStep(step) {
        document.getElementById('aiscan-step-upload').classList.toggle('d-none', step !== 'upload');
        document.getElementById('aiscan-step-review').classList.toggle('d-none', step !== 'review');
        document.getElementById('aiscan-step-import').classList.toggle('d-none', step !== 'import');
    }

    function resetUploadUI() {
        state.documents.forEach(doc => {
            if (doc.objectUrl) {
                URL.revokeObjectURL(doc.objectUrl);
            }
        });
        state.documents = [];
        state.currentIndex = 0;
        state.selectedIndices.clear();
        state.selectionAnchorIndex = null;

        const dropZone = document.getElementById('aiscan-drop-zone');
        dropZone.innerHTML = `
            <div>
                <div class="fs-2 mb-2 text-muted"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                <div class="fw-semibold">${escapeHtml(trans('aiscan-document-and-image-drop'))}</div>
                <div class="small text-muted mt-1">${escapeHtml(trans('aiscan-drop-or-click'))}</div>
                <div class="small text-muted mt-1">PDF, JPG, PNG, WebP</div>
            </div>
        `;

        document.getElementById('aiscan-file-input').value = '';
        document.getElementById('aiscan-upload-btn').disabled = true;
        document.getElementById('aiscan-upload-btn').innerHTML = `<i class="fa-solid fa-cloud-arrow-up me-1"></i>${escapeHtml(trans('aiscan-upload-and-analyze'))}`;

        const fileList = document.getElementById('aiscan-file-list');
        if (fileList) {
            fileList.classList.add('d-none');
        }
    }

    function buildProviderSelect() {
        const select = document.getElementById('aiscan-provider-select');
        if (!select) {
            return;
        }
        select.innerHTML = '';
        (state.availableModels || []).forEach(choice => {
            const opt = document.createElement('option');
            opt.value = choice.provider;
            opt.dataset.model = choice.model || '';
            opt.textContent = choice.label || choiceLabel(choice);
            if (sameChoice(choice, {provider: state.defaultProvider, model: state.defaultModel})) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });
    }

    /**
     * Re-analyze split button: the main button repeats the document's model,
     * the dropdown re-runs it with any other configured model (#89).
     */
    function bindReanalyzeMenu() {
        const menu = document.getElementById('aiscan-reanalyze-menu');
        if (!menu) {
            return;
        }
        menu.addEventListener('click', (e) => {
            const item = e.target.closest('[data-provider]');
            if (!item) {
                return;
            }
            e.preventDefault();
            reanalyzeCurrentDoc({provider: item.dataset.provider, model: item.dataset.model || ''});
        });
    }

    function renderReanalyzeMenu(doc) {
        const menu = document.getElementById('aiscan-reanalyze-menu');
        if (!menu) {
            return;
        }
        // Avoid launching a second analysis while one is running (#120).
        const analyzing = !!doc && doc.status === STATUS.ANALYZING;
        ['aiscan-reanalyze-btn', 'aiscan-reanalyze-menu-btn'].forEach(id => {
            const btn = document.getElementById(id);
            if (btn) {
                btn.disabled = analyzing;
            }
        });
        const current = modelChoiceForDoc(doc);
        const configured = state.configuredDefault;
        const items = (state.availableModels || []).map(choice => {
            const isCurrent = sameChoice(choice, current);
            const check = isCurrent
                ? '<i class="fa-solid fa-check text-primary me-2"></i>'
                : '<span class="d-inline-block me-2" style="width:1em"></span>';
            const badge = configured && sameChoice(choice, configured)
                ? ` <span class="badge text-bg-secondary ms-2">${escapeHtml(trans('aiscan-default-model'))}</span>`
                : '';
            return `<li><button type="button" class="dropdown-item" data-provider="${escapeAttr(choice.provider)}" data-model="${escapeAttr(choice.model || '')}">${check}${escapeHtml(choice.label || choiceLabel(choice))}${badge}</button></li>`;
        });

        const toggle = document.getElementById('aiscan-reanalyze-menu-btn');
        if (toggle) {
            toggle.classList.toggle('d-none', items.length < 2);
        }
        if (items.length < 2) {
            menu.innerHTML = '';
            return;
        }

        menu.innerHTML = `<li><h6 class="dropdown-header">${escapeHtml(trans('aiscan-reanalyze-with'))}</h6></li>`
            + items.join('')
            + '<li><hr class="dropdown-divider"></li>'
            + `<li><span class="dropdown-item-text small text-muted">${escapeHtml(trans('aiscan-reanalyze-keeps-mode'))}</span></li>`;
    }

