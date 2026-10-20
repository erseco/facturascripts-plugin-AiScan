    // ── Autocomplete ───────────────────────────────────────────────────
    // Issue #147: piezas comunes de los buscadores de producto (predeterminado,
    // modal y línea) y de proveedor, que antes repetía cada uno.

    function autocompleteNoResultsHtml() {
        return `<div class="list-group-item small text-muted">${escapeHtml(trans('aiscan-no-results'))}</div>`;
    }

    // Botones de resultado de producto. Con idPrefix son opciones navegables con
    // el teclado (role="option" + id para aria-activedescendant).
    function buildProductOptionsHtml(items, idPrefix = '', extraClass = '') {
        return items.map((item, index) => {
            const aria = idPrefix
                ? ` id="${idPrefix}-option-${index}" role="option" aria-selected="false" data-autocomplete-option="1"`
                : '';
            const cls = extraClass ? ' ' + extraClass : '';
            return `<button type="button" class="list-group-item list-group-item-action small py-1${cls}"${aria} data-ref="${escapeAttr(item.referencia)}" data-desc="${escapeAttr(item.description)}">
                    <strong>${escapeHtml(item.referencia)}</strong> <span class="text-muted">${escapeHtml(item.description)}</span>
                </button>`;
        }).join('');
    }

    // Busca en una acción de AiScanInvoice y descarta las respuestas que llegan
    // tarde: solo la última consulta resuelve con resultados, las anteriores con
    // null (también si fallan), para no pintar una lista vieja encima de la nueva.
    function createLatestSearch(action) {
        let requestId = 0;
        return query => {
            const current = ++requestId;
            return apiFetch('AiScanInvoice?' + new URLSearchParams({action, query}))
                .then(readJsonResponse)
                .then(data => {
                    if (current !== requestId) {
                        return null;
                    }
                    return Array.isArray(data.results) ? data.results : [];
                })
                .catch(error => {
                    if (current !== requestId) {
                        return null;
                    }
                    throw error;
                });
        };
    }

    function closeAutocomplete(resultsDiv, input) {
        resultsDiv.innerHTML = '';
        resultsDiv.classList.add('d-none');
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    // Marca la opción activa y la anuncia con aria-activedescendant.
    function highlightAutocompleteOption(resultsDiv, input, activeIndex) {
        const options = Array.from(resultsDiv?.querySelectorAll(AUTOCOMPLETE_OPTION_SELECTOR) || []);
        options.forEach((option, index) => {
            const isActive = index === activeIndex;
            option.classList.toggle('active', isActive);
            option.setAttribute('aria-selected', isActive ? 'true' : 'false');
            if (isActive && input) {
                input.setAttribute('aria-activedescendant', option.id);
            }
            if (isActive && typeof option.scrollIntoView === 'function') {
                option.scrollIntoView({block: 'nearest'});
            }
        });
        if (input && (activeIndex < 0 || !options[activeIndex])) {
            input.removeAttribute('aria-activedescendant');
        }
        return options;
    }

    // Issue #147: datos del proveedor elegido, venga de la búsqueda, de la
    // desambiguación, del alta o de un CIF que ya existía.
    function applySelectedSupplier(supplier, selected, matchStatus) {
        return Object.assign(supplier, {
            matched_supplier_id: selected.id,
            matched_name: selected.name,
            match_status: matchStatus,
            name: selected.name,
            tax_id: selected.tax_id || '',
        });
    }

