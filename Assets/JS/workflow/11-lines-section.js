    function buildTotalLines(data) {
        const invoice = data.invoice || {};
        const taxes = Array.isArray(data.taxes) ? data.taxes : [];
        const desc = invoice.summary || '';
        const withholding = parseFloat(invoice.withholding_amount) || 0;
        const subtotal = parseFloat(invoice.subtotal) || 0;
        const irpfRate = subtotal > 0 && withholding > 0
            ? Math.round((withholding / subtotal) * 10000) / 100
            : 0;
        // Issue #69: si hay producto fijado/sugerido, enlazarlo en líneas sintéticas.
        const pinnedRef = data._product_suggestion?.referencia || '';
        const pinFields = pinnedRef
            ? {referencia: pinnedRef, referencia_source: data._product_suggestion?.source || 'pinned'}
            : {};

        if (taxes.length > 0) {
            return taxes.map(t => ({
                descripcion: desc,
                cantidad: 1,
                pvpunitario: parseFloat(t.base) || 0,
                dtopor: 0,
                iva: parseFloat(t.rate) || 0,
                irpf: irpfRate,
                ...pinFields,
            }));
        }

        const base = subtotal || parseFloat(invoice.total) || 0;
        const taxAmount = parseFloat(invoice.tax_amount) || 0;
        const taxRate = base > 0 && taxAmount > 0
            ? Math.round((taxAmount / base) * 10000) / 100
            : 0;
        return [{
            descripcion: desc,
            cantidad: 1,
            pvpunitario: base,
            dtopor: 0,
            iva: taxRate,
            irpf: irpfRate,
            ...pinFields,
        }];
    }

    function buildLinesSection(lines) {
        const displayLines = lines.length > 0 ? lines : [{descripcion: '', cantidad: 1, pvpunitario: 0, dtopor: 0, iva: 0, irpf: 0}];

        const rows = displayLines.map((line, index) => buildLineRow(line, index)).join('');

        const section = buildSection(trans('aiscan-section-products'), `
            <div class="d-flex gap-1 align-items-center py-1 border-bottom mb-1 small text-muted">
                <div style="flex:4">${escapeHtml(trans('description'))}</div>
                <div style="width:55px">${escapeHtml(trans('quantity-short'))}</div>
                <div style="width:95px">${escapeHtml(trans('price'))}</div>
                <div style="width:85px">${escapeHtml(trans('total'))}</div>
                <div style="width:56px"></div>
            </div>
            <div id="aiscan-lines-body">${rows}</div>
            <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="aiscan-add-line-btn">
                <i class="fa-solid fa-plus me-1"></i>${escapeHtml(trans('aiscan-add-line'))}
            </button>
        `);

        setTimeout(() => {
            initTaxSelects(section);
            calcAllLineTotals();
            section.querySelectorAll('.aiscan-line-row').forEach(bindLineProductAutocomplete);
            section.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                new bootstrap.Tooltip(el);
            });
        }, 0);

        // Make line detail modals draggable by their header
        section.addEventListener('mousedown', e => {
            const header = e.target.closest('.modal-header');
            if (!header) { return; }
            const dialog = header.closest('.modal-dialog');
            if (!dialog) { return; }
            e.preventDefault();
            const startX = e.clientX, startY = e.clientY;
            const rect = dialog.getBoundingClientRect();
            const origLeft = rect.left, origTop = rect.top;
            dialog.style.margin = '0';
            dialog.style.position = 'fixed';
            dialog.style.left = origLeft + 'px';
            dialog.style.top = origTop + 'px';
            header.style.cursor = 'grabbing';
            function onMove(ev) {
                dialog.style.left = (origLeft + ev.clientX - startX) + 'px';
                dialog.style.top = (origTop + ev.clientY - startY) + 'px';
            }
            function onUp() {
                header.style.cursor = '';
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        // Save modal state on open, restore on cancel/dismiss
        section.addEventListener('show.bs.modal', e => {
            const modal = e.target;
            if (!modal) { return; }
            // Reset drag position
            const dialog = modal.querySelector('.modal-dialog');
            if (dialog) {
                dialog.style.position = '';
                dialog.style.left = '';
                dialog.style.top = '';
                dialog.style.margin = '';
            }
            // Sync current row values into modal before opening
            const row = modal.closest('.aiscan-line-row');
            if (row) {
                syncRowToModal(row, modal);
            }
            const snapshot = {};
            modal.querySelectorAll('input, select').forEach(el => {
                snapshot[Array.from(el.classList).join('.') || el.type] = el.value;
            });
            modal._snapshot = snapshot;
            modal._accepted = false;
        });
        section.addEventListener('shown.bs.modal', e => {
            const modal = e.target;
            if (modal) {
                calcModalPreview(modal);
            }
        });
        section.addEventListener('hide.bs.modal', e => {
            const modal = e.target;
            if (!modal || modal._accepted) { return; }
            const snapshot = modal._snapshot;
            if (!snapshot) { return; }
            const keys = Object.keys(snapshot);
            let i = 0;
            modal.querySelectorAll('input, select').forEach(el => {
                const key = Array.from(el.classList).join('.') || el.type;
                if (keys[i] === key) {
                    el.value = snapshot[key];
                    i++;
                }
            });
        });

        // Recalculate line totals on any value change
        section.addEventListener('input', e => {
            if (e.target.closest('.aiscan-calc')) {
                calcAllLineTotals();
            }
        });
        // Modal: recalculate preview on input (without syncing to row)
        section.addEventListener('input', e => {
            const modal = e.target.closest('.modal');
            if (modal) {
                if (e.target.classList.contains('aiscan-modal-discount-amount')) {
                    syncModalDiscountFields(modal, 'amount');
                } else if (
                    e.target.classList.contains('aiscan-modal-discount')
                    || e.target.classList.contains('aiscan-modal-quantity')
                    || e.target.classList.contains('aiscan-modal-price')
                ) {
                    syncModalDiscountFields(modal, 'percent');
                }
                calcModalPreview(modal);
                return;
            }
        });
        section.addEventListener('change', e => {
            const modal = e.target.closest('.modal');
            if (modal) {
                calcModalPreview(modal);
                return;
            }
            if (e.target.matches('[data-field="iva"]') || e.target.matches('[data-field="irpf"]')) {
                calcAllLineTotals();
            }
        });

        section.addEventListener('click', e => {
            const deleteBtn = e.target.closest('.aiscan-delete-line');
            if (deleteBtn) {
                const row = deleteBtn.closest('.aiscan-line-row');
                if (row && document.querySelectorAll('#aiscan-lines-body .aiscan-line-row').length > 1) {
                    row.remove();
                    calcAllLineTotals();
                }
            }
            const addBtn = e.target.closest('#aiscan-add-line-btn');
            if (addBtn) {
                const container = document.getElementById('aiscan-lines-body');
                const newIndex = container.querySelectorAll('.aiscan-line-row').length;
                container.insertAdjacentHTML('beforeend', buildLineRow(
                    {descripcion: '', cantidad: 1, pvpunitario: 0, dtopor: 0, iva: 0, irpf: 0}, newIndex
                ));
                calcAllLineTotals();
                bindLineProductAutocomplete(container.lastElementChild);
            }

            // Modal accept — sync values to row, then close
            const acceptBtn = e.target.closest('.aiscan-modal-accept');
            if (acceptBtn) {
                const modal = acceptBtn.closest('.modal');
                const row = modal?.closest('.aiscan-line-row');
                if (modal && row) {
                    modal._accepted = true;
                    applyModalToRow(modal, row);
                    bootstrap.Modal.getInstance(modal)?.hide();
                }
            }

            // Product search button — open shared modal
            const matchBtn = e.target.closest('.aiscan-product-match-btn');
            if (matchBtn) {
                const row = matchBtn.closest('.aiscan-line-row');
                openProductSearchModal(row);
            }
        });

        return section;
    }

