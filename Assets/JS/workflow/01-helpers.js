    // ── Helpers ────────────────────────────────────────────────────────

    // Issue #135: un 4xx/5xx no se puede tratar como una respuesta buena. Si trae
    // JSON se devuelve marcado como error (los llamantes ya miran success/error);
    // si no, se lanza para que lo recoja el catch de quien llama.
    async function readJsonResponse(response) {
        let data = null;
        try {
            data = await response.json();
        } catch (e) {
            data = null;
        }
        if (response.ok && data !== null) {
            return data;
        }
        const message = data && data.error
            ? data.error
            : trans('aiscan-request-failed', {'%status%': String(response.status)});
        if (!response.ok && data && typeof data === 'object') {
            return {...data, success: false, error: message};
        }
        throw new Error(message);
    }

    // Issue #135: durante el análisis en paralelo, cada documento que terminaba
    // repintaba el formulario del documento abierto y borraba lo que el usuario
    // estaba escribiendo. Solo se repinta el documento que ha cambiado.
    function resolveReviewRefresh(changedDoc, current, modalOpen) {
        if (!current || (changedDoc && changedDoc !== current)) {
            return 'skip';
        }
        const reviewable = [STATUS.ANALYZING, STATUS.ANALYZED, STATUS.NEEDS_REVIEW, STATUS.FAILED];
        if (!reviewable.includes(current.status)) {
            return 'skip';
        }
        return modalOpen ? 'defer' : 'render';
    }

    let formTokenCounter = 0;

    // Issue #129: el servidor rechaza un token ya usado, así que cada petición
    // lleva el token de la página con un sufijo distinto (solo se firma la
    // primera parte del token).
    function withFormToken(url) {
        const token = window.aiscanFormToken || '';
        if (!token) {
            return url;
        }
        formTokenCounter += 1;
        const separator = url.indexOf('?') === -1 ? '?' : '&';
        return url + separator + 'multireqtoken=' + encodeURIComponent(token + formTokenCounter);
    }

    function apiFetch(url, options) {
        return fetch(withFormToken(url), options);
    }

    function trans(key, replacements) {
        let value = key;
        if (window.i18n && typeof window.i18n.trans === 'function') {
            value = window.i18n.trans(key);
        }
        if (value === key && window.aiscanI18n && window.aiscanI18n[key]) {
            value = window.aiscanI18n[key];
        }
        if (value === key) {
            return key;
        }
        if (replacements) {
            Object.entries(replacements).forEach(([k, v]) => {
                value = value.replaceAll(k, v == null ? '' : String(v));
            });
        }
        return value;
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/"/g, '&quot;');
    }

    function checkTotalMismatch(data) {
        if (!data?.invoice?.total || !data?.lines?.length) {
            return null;
        }
        const invoiceTotal = parseFloat(data.invoice.total) || 0;
        let calcTotal = 0;
        for (const line of data.lines) {
            const qty = parseFloat(line.cantidad ?? line.quantity ?? 1);
            const price = parseFloat(line.pvpunitario ?? line.unit_price ?? 0);
            const dto = parseFloat(line.dtopor ?? line.discount ?? 0);
            const tax = parseFloat(line.iva ?? line.tax_rate ?? 0);
            const irpf = parseFloat(line.irpf ?? 0);
            const base = qty * price * (1 - dto / 100);
            calcTotal += base + base * (tax / 100) - base * (irpf / 100);
        }
        const diffCents = Math.round(calcTotal * 100) - Math.round(invoiceTotal * 100);
        if (diffCents !== 0) {
            return {
                message: trans('aiscan-total-mismatch', {
                    '%calculated%': fmtNumber(calcTotal),
                    '%invoice%': fmtNumber(invoiceTotal),
                }),
                isRounding: Math.abs(diffCents) <= 5,
            };
        }
        return null;
    }

    function filterOutDynamicWarnings(data) {
        const staticValidationErrors = Array.isArray(data?._validation_errors) ? data._validation_errors : [];
        const dynamicWarnings = [
            data?._total_mismatch_warning,
            trans('aiscan-missing-supplier-tax-id'),
            trans('aiscan-supplier-name-required'),
        ].filter(Boolean);
        return staticValidationErrors.filter(message => !dynamicWarnings.includes(message));
    }

    function getValidationWarnings(data) {
        const warnings = filterOutDynamicWarnings(data);
        const totalMismatch = checkTotalMismatch(data);
        if (totalMismatch) {
            warnings.push(totalMismatch.message);
        }

        // Issue #78: always surface blocking identity errors in the warnings list.
        getBlockingImportErrors(data).forEach(msg => {
            if (msg && !warnings.includes(msg)) {
                warnings.push(msg);
            }
        });

        return warnings;
    }

    /**
     * Vital fields required before marking ready / importing (#78).
     * Mirrors SchemaValidator::getImportBlockingErrors().
     */
    function getBlockingImportErrors(data) {
        const errors = [];
        if (!data || typeof data !== 'object') {
            return errors;
        }

        const invoice = data.invoice || {};
        const supplier = data.supplier || {};
        const number = String(invoice.number ?? '').trim();
        const issueDate = String(invoice.issue_date ?? '').trim();
        const matchedId = String(supplier.matched_supplier_id ?? '').trim();
        const name = String(supplier.name ?? '').trim();
        const taxId = String(supplier.tax_id ?? '').trim();

        if (!number) {
            errors.push(trans('aiscan-missing-required-invoice-field', {
                '%field%': trans('number'),
            }));
        }
        if (!issueDate) {
            errors.push(trans('aiscan-missing-required-invoice-field', {
                '%field%': trans('date'),
            }));
        }

        if (!matchedId) {
            if (!name) {
                errors.push(trans('aiscan-supplier-name-required'));
            }
            if (!taxId) {
                errors.push(trans('aiscan-missing-supplier-tax-id'));
            }
        }

        return errors;
    }

    function canMarkDocReady(data) {
        return getBlockingImportErrors(data).length === 0;
    }

    /**
     * If a previously approved doc loses vital fields, revoke READY (#78).
     * @returns {boolean} true when status/decision changed
     */
    function revokeReadyIfBlocked(doc, data) {
        if (!doc || doc.status !== STATUS.READY) {
            return false;
        }
        if (canMarkDocReady(data || doc.extractedData)) {
            return false;
        }
        doc.status = STATUS.NEEDS_REVIEW;
        doc.reviewDecision = null;
        return true;
    }

    function renderValidationWarningsHtml(validationErrors) {
        if (validationErrors.length === 0) {
            return '';
        }

        return `
            <strong class="small"><i class="fa-solid fa-triangle-exclamation me-1"></i>${escapeHtml(trans('aiscan-validation-warnings'))}</strong>
            <ul class="mb-0 small">${validationErrors.map(e => `<li>${escapeHtml(e)}</li>`).join('')}</ul>
        `;
    }

    function updateValidationWarnings() {
        const container = document.getElementById('aiscan-validation-warnings');
        const doc = currentDoc();
        if (!container || !doc?.extractedData) {
            updateMarkReadyButton();
            return;
        }

        const data = collectFormData(doc.extractedData);
        const validationErrors = getValidationWarnings(data);
        if (validationErrors.length === 0) {
            container.className = 'd-none';
            container.innerHTML = '';
        } else {
            container.className = 'alert alert-danger py-2';
            container.innerHTML = renderValidationWarningsHtml(validationErrors);
        }

        updateMarkReadyButton(data);
    }

    function updateMarkReadyButton(dataOverride) {
        const btn = document.getElementById('aiscan-mark-ready-btn');
        const hint = document.getElementById('aiscan-mark-ready-hint');
        const doc = currentDoc();
        const data = dataOverride
            || (doc?.extractedData ? collectFormData(doc.extractedData) : null);
        const blocking = getBlockingImportErrors(data);
        const canReady = blocking.length === 0;

        if (doc && data) {
            // Keep extractedData in sync with the form before status checks.
            doc.extractedData = data;
            if (revokeReadyIfBlocked(doc, data)) {
                renderSidebar();
            }
        }

        if (!btn) {
            return;
        }

        // Issue #127: show that the invoice is already marked as ready.
        const isReady = canReady && doc?.status === STATUS.READY;
        btn.disabled = !canReady || isReady;
        btn.title = canReady
            ? ''
            : (trans('aiscan-cannot-mark-ready') || blocking.join(' '));
        btn.innerHTML = `<i class="fa-solid fa-check me-1"></i>${escapeHtml(
            trans(isReady ? 'aiscan-marked-ready' : 'aiscan-mark-ready'))}`;

        if (hint) {
            if (canReady) {
                hint.classList.add('d-none');
                hint.innerHTML = '';
            } else {
                hint.classList.remove('d-none');
                const list = blocking.map(e => `<li>${escapeHtml(e)}</li>`).join('');
                hint.innerHTML = `
                    <strong class="small"><i class="fa-solid fa-ban me-1"></i>${escapeHtml(trans('aiscan-cannot-mark-ready'))}</strong>
                    <ul class="mb-1 small">${list}</ul>
                    <p class="mb-0 small text-muted">${escapeHtml(trans('aiscan-rescan-hint'))}</p>
                `;
            }
        }
    }

    function checkInBatchDuplicate(doc) {
        const inv = doc.extractedData?.invoice;
        if (!inv?.number) {
            return null;
        }
        const supplierId = doc.extractedData?.supplier?.matched_supplier_id || '';
        for (const other of state.documents) {
            if (other === doc || !other.extractedData?.invoice?.number) {
                continue;
            }
            const otherInv = other.extractedData.invoice;
            const otherSupplier = other.extractedData.supplier?.matched_supplier_id || '';
            if (
                otherInv.number === inv.number
                && otherInv.issue_date === inv.issue_date
                && otherSupplier === supplierId
                && Math.abs((otherInv.total || 0) - (inv.total || 0)) < 0.02
                && (other.status === STATUS.ANALYZED || other.status === STATUS.READY || other.status === STATUS.NEEDS_REVIEW)
            ) {
                return trans('aiscan-duplicate-in-batch', {'%name%': other.originalName});
            }
        }
        return null;
    }

    function currentDoc() {
        return state.documents[state.currentIndex] || null;
    }

    function applySelectionRange(selectedIndices, sortedIndices, anchorIndex, targetIndex, checked) {
        const anchorPos = sortedIndices.indexOf(anchorIndex);
        const targetPos = sortedIndices.indexOf(targetIndex);
        if (anchorPos === -1 || targetPos === -1) {
            return false;
        }

        const start = Math.min(anchorPos, targetPos);
        const end = Math.max(anchorPos, targetPos);

        for (let position = start; position <= end; position++) {
            const index = sortedIndices[position];
            if (checked) {
                selectedIndices.add(index);
            } else {
                selectedIndices.delete(index);
            }
        }

        return true;
    }

    function resolveAutocompleteKeyAction(key, isOpen, activeIndex, itemCount) {
        if (!isOpen || itemCount < 1) {
            return null;
        }

        if (key === 'ArrowDown') {
            return {
                type: 'highlight',
                index: Math.min(itemCount - 1, activeIndex < 0 ? 0 : activeIndex + 1),
                preventDefault: true,
            };
        }

        if (key === 'ArrowUp') {
            return {
                type: 'highlight',
                index: Math.max(0, activeIndex < 0 ? itemCount - 1 : activeIndex - 1),
                preventDefault: true,
            };
        }

        if (key === 'Enter' || key === 'Tab') {
            return {
                type: 'select',
                index: activeIndex < 0 ? 0 : Math.min(activeIndex, itemCount - 1),
                moveFocus: key === 'Tab',
                preventDefault: true,
            };
        }

        if (key === 'Escape') {
            return {type: 'close', preventDefault: true};
        }

        return null;
    }

