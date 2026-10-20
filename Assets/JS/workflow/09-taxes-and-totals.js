    function buildPaymentMethodSelect(selectedCode) {
        const methods = window.aiscanPaymentMethods || [];
        const code = selectedCode || '';
        let options = '';
        for (const m of methods) {
            const sel = m.code === code;
            options += `<option value="${escapeAttr(m.code)}"${sel ? ' selected' : ''}>${escapeHtml(m.description)}</option>`;
        }
        return `<select class="form-select form-select-sm" id="invoice_codpago">${options}</select>`;
    }

    function buildTaxSelect(selectedRate, selectedCode) {
        const taxes = window.aiscanTaxTypes || [];
        const rate = parseFloat(selectedRate) || 0;
        const code = selectedCode || '';
        let options = '<option value="">------</option>';
        let found = false;
        for (const t of taxes) {
            const sel = (code && t.code === code) || (!code && !found && Math.abs(t.rate - rate) < 0.01);
            if (sel) {
                found = true;
            }
            options += `<option value="${escapeAttr(t.code)}"${sel ? ' selected' : ''}>${escapeHtml(t.description)}</option>`;
        }
        return `<select class="form-select form-select-sm" data-field="tax_code" style="width:120px">${options}</select>`;
    }

    // Issue #95: los códigos de excepción de IVA cambiaron en FacturaScripts 2026
    // (ES_PASSIVE_SUBJECT -> ES_84, ES_N1 -> ES_7...). La lista la envía el core
    // para no guardar códigos que el programa ya no reconoce.
    function buildTaxExceptionSelect(selectedCode) {
        const exceptions = window.aiscanTaxExceptions || [];
        const code = selectedCode || '';
        let options = `<option value=""${code === '' ? ' selected' : ''}>------</option>`;
        for (const e of exceptions) {
            const sel = code === e.code ? ' selected' : '';
            options += `<option value="${escapeAttr(e.code)}"${sel}>${escapeHtml(e.description)}</option>`;
        }
        return `<select class="form-select form-select-sm aiscan-modal-excepcioniva">${options}</select>`;
    }

    function buildWithholdingSelect(selectedRate, selectedCode) {
        const types = window.aiscanWithholdingTypes || [];
        const rate = parseFloat(selectedRate) || 0;
        const code = selectedCode || '';
        let options = '<option value="">------</option>';
        for (const t of types) {
            if (!t.code) {
                continue;
            }
            const sel = (code && t.code === code) || (!code && rate > 0 && Math.abs(t.rate - rate) < 0.01);
            options += `<option value="${escapeAttr(t.rate)}"${sel ? ' selected' : ''}>${escapeHtml(t.description)}</option>`;
        }
        return `<select class="form-select form-select-sm" data-field="irpf" style="width:120px">${options}</select>`;
    }

    function initTaxSelects(container) {
        container.querySelectorAll('.aiscan-line-row').forEach(row => {
            const rate = parseFloat(row.dataset.taxRate || 0);
            const taxCode = row.dataset.taxCode || '';
            const irpfCode = row.dataset.irpfCode || '';

            // Sync hidden iva field
            const hiddenTax = row.querySelector('[data-field="iva"]');
            if (hiddenTax) {
                hiddenTax.value = rate;
            }

            // Select tax in modal — prefer code match, fallback to rate match
            const modalTax = row.querySelector('.aiscan-modal-tax');
            if (modalTax) {
                let found = false;
                if (taxCode) {
                    const taxes = window.aiscanTaxTypes || [];
                    const match = taxes.find(t => t.code === taxCode);
                    if (match) {
                        for (const opt of modalTax.options) {
                            if (parseFloat(opt.value) === match.rate) {
                                opt.selected = true;
                                found = true;
                                break;
                            }
                        }
                    }
                }
                if (!found) {
                    for (const opt of modalTax.options) {
                        if (Math.abs(parseFloat(opt.value) - rate) < 0.01) {
                            opt.selected = true;
                            break;
                        }
                    }
                }
            }

            // Select IRPF in modal and sync codretencion + hidden irpf
            const modalIrpf = row.querySelector('.aiscan-modal-irpf');
            const irpfHidden = row.querySelector('[data-field="irpf"]');
            const codretHidden = row.querySelector('[data-field="codretencion"]');
            const irpfVal = parseFloat(irpfHidden?.value) || 0;
            if (modalIrpf && irpfVal > 0) {
                const types = window.aiscanWithholdingTypes || [];
                let match = irpfCode ? types.find(t => t.code === irpfCode) : null;
                if (!match) {
                    match = types.find(t => Math.abs(t.rate - irpfVal) < 0.01);
                }
                if (!match) {
                    // Find closest available rate
                    let closest = null;
                    let minDiff = Infinity;
                    for (const t of types) {
                        const diff = Math.abs(t.rate - irpfVal);
                        if (diff < minDiff) { minDiff = diff; closest = t; }
                    }
                    if (closest && minDiff < 5) { match = closest; }
                }
                if (match) {
                    for (const opt of modalIrpf.options) {
                        if (parseFloat(opt.value) === match.rate) {
                            opt.selected = true;
                            break;
                        }
                    }
                    if (irpfHidden) { irpfHidden.value = match.rate; }
                    if (codretHidden) { codretHidden.value = match.code; }
                } else {
                    // No matching type — reset to 0
                    modalIrpf.selectedIndex = 0;
                    if (irpfHidden) { irpfHidden.value = 0; }
                    if (codretHidden) { codretHidden.value = ''; }
                }
            }
        });
    }

    function fmtNumber(n) {
        return n.toLocaleString(document.documentElement.lang || 'es', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function localeDecimalSeparator(lang) {
        try {
            const part = new Intl.NumberFormat(lang || 'es').formatToParts(1.5).find(p => p.type === 'decimal');
            return part ? part.value : ',';
        } catch (e) {
            return ',';
        }
    }

    // Issue #135: los importes se pintan con fmtNumber() en el idioma de la página
    // («1.234,56», «1,234.56», «1 234,56»), y el usuario puede teclear el decimal
    // con punto o con coma. Un separador que aparece una sola vez y no agrupa de
    // tres en tres es el decimal, aunque el idioma diga otra cosa.
    function parseLocaleNumber(str, lang) {
        let value = String(str ?? '').replace(/[\s\u00a0\u202f']/g, '');
        if (value === '' || value === '-') {
            return 0;
        }
        const lastComma = value.lastIndexOf(',');
        const lastDot = value.lastIndexOf('.');
        let decimal = null;
        if (lastComma !== -1 && lastDot !== -1) {
            decimal = lastComma > lastDot ? ',' : '.';
        } else if (lastComma !== -1 || lastDot !== -1) {
            const sep = lastComma !== -1 ? ',' : '.';
            const grouped = new RegExp('^-?\\d{1,3}(\\' + sep + '\\d{3})+$').test(value);
            const isLocaleDecimal = sep === localeDecimalSeparator(lang || document.documentElement.lang);
            decimal = (isLocaleDecimal || !grouped) ? sep : null;
        }
        const group = decimal === ',' ? /\./g : /,/g;
        value = value.replace(group, '');
        if (decimal === null) {
            value = value.replace(/[.,]/g, '');
        } else if (decimal === ',') {
            value = value.replace(',', '.');
        }
        return parseFloat(value) || 0;
    }

    function calcLineTotal(row) {
        const qty = parseFloat(row.querySelector('[data-field="cantidad"]')?.value || 0);
        const price = parseFloat(row.querySelector('[data-field="pvpunitario"]')?.value || 0);
        const discount = parseFloat(row.querySelector('[data-field="dtopor"]')?.value || 0);
        const tax = parseFloat(row.querySelector('[data-field="iva"]')?.value || 0);
        const irpf = parseFloat(row.querySelector('[data-field="irpf"]')?.value || 0);
        const base = qty * price * (1 - discount / 100);
        const taxAmount = base * (tax / 100);
        const irpfAmount = base * (irpf / 100);
        const total = base + taxAmount - irpfAmount;

        const totalInput = row.querySelector('.aiscan-line-total');
        if (totalInput) {
            totalInput.value = fmtNumber(total);
        }
        const modalTotal = row.querySelector('.aiscan-modal-total');
        if (modalTotal) {
            modalTotal.textContent = fmtNumber(total);
        }
        // Update modal summary if present
        const modalBase = row.querySelector('.aiscan-modal-base');
        if (modalBase) {
            modalBase.textContent = fmtNumber(base);
        }
        const modalTax = row.querySelector('.aiscan-modal-tax-amount');
        if (modalTax) {
            modalTax.textContent = fmtNumber(taxAmount);
        }
        const modalIrpf = row.querySelector('.aiscan-modal-irpf-amount');
        if (modalIrpf) {
            modalIrpf.textContent = fmtNumber(irpfAmount);
        }
    }

    function calcAllLineTotals() {
        let totalBase = 0;
        let totalTax = 0;
        let totalIrpf = 0;
        let totalFinal = 0;
        document.querySelectorAll('#aiscan-lines-body .aiscan-line-row').forEach(row => {
            calcLineTotal(row);
            const qty = parseFloat(row.querySelector('[data-field="cantidad"]')?.value || 0);
            const price = parseFloat(row.querySelector('[data-field="pvpunitario"]')?.value || 0);
            const discount = parseFloat(row.querySelector('[data-field="dtopor"]')?.value || 0);
            const tax = parseFloat(row.querySelector('[data-field="iva"]')?.value || 0);
            const irpf = parseFloat(row.querySelector('[data-field="irpf"]')?.value || 0);
            const base = qty * price * (1 - discount / 100);
            totalBase += base;
            totalTax += base * (tax / 100);
            totalIrpf += base * (irpf / 100);
            totalFinal += base + base * (tax / 100) - base * (irpf / 100);
        });
        const subtotalEl = document.getElementById('invoice_subtotal');
        if (subtotalEl) { subtotalEl.value = fmtNumber(totalBase); }
        const taxEl = document.getElementById('invoice_tax_amount');
        if (taxEl) { taxEl.value = fmtNumber(totalTax); }
        const irpfEl = document.getElementById('invoice_withholding');
        if (irpfEl) { irpfEl.value = fmtNumber(totalIrpf); }
        const totalEl = document.getElementById('invoice_total');
        if (totalEl) { totalEl.value = fmtNumber(totalFinal); }
        updateValidationWarnings();

        // Detect rounding mismatch with original invoice total
        const roundingAlert = document.getElementById('aiscan-rounding-alert');
        if (roundingAlert) {
            const doc = currentDoc();
            const origTotal = parseFloat(doc?.extractedData?.invoice?.total) || 0;
            const diffCents = Math.round(totalFinal * 100) - Math.round(origTotal * 100);
            if (origTotal > 0 && diffCents !== 0 && Math.abs(diffCents) <= 5) {
                const diff = diffCents / 100;
                roundingAlert.className = 'alert alert-info py-1 px-2 small mt-2';
                roundingAlert.innerHTML = `<i class="fa-solid fa-info-circle me-1"></i>${escapeHtml(trans('aiscan-rounding-diff', {'%diff%': fmtNumber(diff)}))}`;
            } else {
                roundingAlert.className = 'd-none';
                roundingAlert.innerHTML = '';
            }
        }
    }

    function taxCodeToRate(code) {
        const taxes = window.aiscanTaxTypes || [];
        const match = taxes.find(t => t.code === code);
        return match ? match.rate : 0;
    }

    function lineDiscountBase(qty, price) {
        const quantity = Number(qty) || 0;
        const unitPrice = Number(price) || 0;
        return quantity * unitPrice;
    }

    function discountPercentFromAmount(qty, price, amount) {
        const base = lineDiscountBase(qty, price);
        if (Math.abs(base) < 1e-9) {
            return 0;
        }
        return (Number(amount) || 0) / base * 100;
    }

    function discountAmountFromPercent(qty, price, percent) {
        return lineDiscountBase(qty, price) * ((Number(percent) || 0) / 100);
    }

    function formatDiscountValue(value) {
        if (!Number.isFinite(value)) {
            return '0';
        }
        const rounded = Math.round(value * 1e6) / 1e6;
        return String(rounded);
    }

    function syncModalDiscountFields(modal, source) {
        const qty = parseFloat(modal.querySelector('.aiscan-modal-quantity')?.value || 0);
        const price = parseFloat(modal.querySelector('.aiscan-modal-price')?.value || 0);
        const pctEl = modal.querySelector('.aiscan-modal-discount');
        const amtEl = modal.querySelector('.aiscan-modal-discount-amount');
        if (!pctEl || !amtEl) {
            return;
        }
        if (source === 'amount') {
            pctEl.value = formatDiscountValue(discountPercentFromAmount(qty, price, amtEl.value));
            return;
        }
        amtEl.value = formatDiscountValue(discountAmountFromPercent(qty, price, pctEl.value));
    }

    function calcModalPreview(modal) {
        const qty = parseFloat(modal.querySelector('.aiscan-modal-quantity')?.value || 0);
        const price = parseFloat(modal.querySelector('.aiscan-modal-price')?.value || 0);
        const discount = parseFloat(modal.querySelector('.aiscan-modal-discount')?.value || 0);
        const taxCode = modal.querySelector('.aiscan-modal-tax')?.value || '';
        const tax = taxCodeToRate(taxCode);
        const irpf = parseFloat(modal.querySelector('.aiscan-modal-irpf')?.value || 0);
        const base = qty * price * (1 - discount / 100);
        const taxAmt = base * (tax / 100);
        const irpfAmt = base * (irpf / 100);
        const total = base + taxAmt - irpfAmt;
        const b = modal.querySelector('.aiscan-modal-base');
        if (b) { b.textContent = fmtNumber(base); }
        const t = modal.querySelector('.aiscan-modal-tax-amount');
        if (t) { t.textContent = fmtNumber(taxAmt); }
        const i = modal.querySelector('.aiscan-modal-irpf-amount');
        if (i) { i.textContent = fmtNumber(irpfAmt); }
        const tot = modal.querySelector('.aiscan-modal-total');
        if (tot) { tot.textContent = fmtNumber(total); }
    }

    function syncRowToModal(row, modal) {
        const fields = {
            descripcion: '.aiscan-modal-description',
            cantidad: '.aiscan-modal-quantity',
            pvpunitario: '.aiscan-modal-price',
            dtopor: '.aiscan-modal-discount',
        };
        for (const [field, sel] of Object.entries(fields)) {
            const rowInput = row.querySelector(`[data-field="${field}"]`);
            const modalInput = modal.querySelector(sel);
            if (rowInput && modalInput) {
                modalInput.value = rowInput.value;
            }
        }
        syncModalDiscountFields(modal, 'percent');
        const taxRow = row.querySelector('[data-field="codimpuesto"]');
        const taxModal = modal.querySelector('.aiscan-modal-tax');
        if (taxRow && taxModal) {
            taxModal.value = taxRow.value;
        }
        const irpfRow = row.querySelector('[data-field="irpf"]');
        const irpfModal = modal.querySelector('.aiscan-modal-irpf');
        if (irpfRow && irpfModal) {
            irpfModal.value = irpfRow.value;
        }
        const excRow = row.querySelector('[data-field="excepcioniva"]');
        const excModal = modal.querySelector('.aiscan-modal-excepcioniva');
        if (excRow && excModal) {
            excModal.value = excRow.value;
        }
        const supRow = row.querySelector('[data-field="suplido"]');
        const supModal = modal.querySelector('.aiscan-modal-suplido');
        if (supRow && supModal) {
            supModal.value = supRow.value;
        }
    }

    function applyModalToRow(modal, row) {
        const fields = {
            descripcion: '.aiscan-modal-description',
            cantidad: '.aiscan-modal-quantity',
            pvpunitario: '.aiscan-modal-price',
            dtopor: '.aiscan-modal-discount',
        };
        for (const [field, sel] of Object.entries(fields)) {
            const el = modal.querySelector(sel);
            if (el) {
                row.querySelector(`[data-field="${field}"]`).value = el.value;
            }
        }
        const taxModal = modal.querySelector('.aiscan-modal-tax');
        if (taxModal) {
            const selectedCode = taxModal.value;
            row.querySelector('[data-field="codimpuesto"]').value = selectedCode;
            const taxes = window.aiscanTaxTypes || [];
            const match = taxes.find(t => t.code === selectedCode);
            row.querySelector('[data-field="iva"]').value = match ? match.rate : 0;
        }
        const irpfModal = modal.querySelector('.aiscan-modal-irpf');
        if (irpfModal) {
            const irpfRate = parseFloat(irpfModal.value) || 0;
            row.querySelector('[data-field="irpf"]').value = irpfRate;
            const types = window.aiscanWithholdingTypes || [];
            const irpfMatch = types.find(t => Math.abs(t.rate - irpfRate) < 0.01);
            row.querySelector('[data-field="codretencion"]').value = irpfMatch ? irpfMatch.code : '';
        }
        const excModal = modal.querySelector('.aiscan-modal-excepcioniva');
        if (excModal) {
            row.querySelector('[data-field="excepcioniva"]').value = excModal.value;
        }
        const supModal = modal.querySelector('.aiscan-modal-suplido');
        if (supModal) {
            row.querySelector('[data-field="suplido"]').value = supModal.value;
        }
        calcAllLineTotals();
    }

