const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadTestHooks() {
    const scriptPath = path.join(__dirname, '..', '..', 'Assets', 'JS', 'aiscan-workflow.js');
    const script = fs.readFileSync(scriptPath, 'utf8');
    const elements = {};

    function getElement(id) {
        if (!elements[id]) {
            elements[id] = {
                id,
                innerHTML: '',
                checked: false,
                disabled: false,
                indeterminate: false,
                value: '',
                querySelectorAll() {
                    return [];
                },
                addEventListener() {},
                classList: {
                    add() {},
                    remove() {},
                    toggle() {},
                },
            };
        }

        return elements[id];
    }

    const context = {
        console,
        setTimeout,
        clearTimeout,
        URL: {
            createObjectURL() {
                return 'blob:test';
            },
            revokeObjectURL() {},
        },
        bootstrap: {
            Tooltip: {
                getInstance() {
                    return null;
                },
            },
        },
        document: {
            addEventListener() {},
            documentElement: {
                lang: 'es',
            },
            getElementById(id) {
                return getElement(id);
            },
            querySelectorAll() {
                return [];
            },
            createElement() {
                return {
                    innerHTML: '',
                    set textContent(value) {
                        this.innerHTML = value === null || value === undefined ? '' : String(value);
                    },
                };
            },
            body: {
                style: {},
            },
        },
    };

    context.globalThis = context;
    context.window = context;
    context.__AISCAN_TEST__ = true;
    context.aiscanPaymentMethods = [
        {code: 'CONT', description: 'Contado'},
        {code: 'TRANS', description: 'Transferencia'},
        {code: 'TAR', description: 'Tarjeta'},
    ];
    context.aiscanDefaultCodpago = 'CONT';

    vm.createContext(context);
    vm.runInContext(script, context);

    return {
        elements,
        hooks: context.__aiscanWorkflowTestHooks,
    };
}

test('applySelectionRange selects the visible range between anchor and target', () => {
    const {hooks} = loadTestHooks();
    const {applySelectionRange} = hooks;
    const selected = new Set([4]);
    const sorted = [10, 4, 7, 2, 9];

    const usedRange = applySelectionRange(selected, sorted, 4, 2, true);

    assert.equal(usedRange, true);
    assert.deepEqual([...selected].sort((a, b) => a - b), [2, 4, 7]);
});

test('applySelectionRange clears the visible range when unchecking with Shift', () => {
    const {hooks} = loadTestHooks();
    const {applySelectionRange} = hooks;
    const selected = new Set([10, 4, 7, 2, 9]);
    const sorted = [10, 4, 7, 2, 9];

    const usedRange = applySelectionRange(selected, sorted, 4, 2, false);

    assert.equal(usedRange, true);
    assert.deepEqual([...selected].sort((a, b) => a - b), [9, 10]);
});

test('applySelectionRange ignores unknown anchors without mutating the selection', () => {
    const {hooks} = loadTestHooks();
    const {applySelectionRange} = hooks;
    const selected = new Set([9]);
    const sorted = [10, 4, 7, 2, 9];

    const usedRange = applySelectionRange(selected, sorted, 3, 2, true);

    assert.equal(usedRange, false);
    assert.deepEqual([...selected], [9]);
});

test('renderSidebar marks the full Shift-selected range as checked', () => {
    const {elements, hooks} = loadTestHooks();

    hooks.state.documents = [
        {originalName: 'Factura 1', status: 'pending', reviewDecision: null, extractedData: {invoice: {number: 'F-001'}, supplier: {name: 'A'}}},
        {originalName: 'Factura 2', status: 'pending', reviewDecision: null, extractedData: {invoice: {number: 'F-002'}, supplier: {name: 'B'}}},
        {originalName: 'Factura 3', status: 'pending', reviewDecision: null, extractedData: {invoice: {number: 'F-003'}, supplier: {name: 'C'}}},
        {originalName: 'Factura 4', status: 'pending', reviewDecision: null, extractedData: {invoice: {number: 'F-004'}, supplier: {name: 'D'}}},
    ];
    hooks.state.selectedIndices = new Set([1, 2, 3]);
    hooks.state.currentIndex = 0;

    hooks.renderSidebar();

    assert.match(elements['aiscan-sidebar-list'].innerHTML, /data-index="1" checked/);
    assert.match(elements['aiscan-sidebar-list'].innerHTML, /data-index="2" checked/);
    assert.match(elements['aiscan-sidebar-list'].innerHTML, /data-index="3" checked/);
});

test('handleMultiInvoiceResponse splits one document into multiple entries', () => {
    const {hooks} = loadTestHooks();

    hooks.state.documents = [
        {
            index: 0,
            file: null,
            originalName: 'batch.pdf',
            mimeType: 'application/pdf',
            size: 1000,
            objectUrl: 'blob:test',
            tmpFile: 'aiscan_batch_abc123.pdf',
            status: 'analyzing',
            extractedData: null,
            error: null,
            reviewDecision: null,
        },
    ];
    hooks.state.importMode = 'lines';

    const invoices = [
        {
            invoice: {number: 'INV-1', issue_date: '2025-01-01', total: 100},
            supplier: {name: 'Supplier A'},
            lines: [{descripcion: 'Item 1', cantidad: 1, pvpunitario: 100}],
            taxes: [],
            confidence: {},
            warnings: [],
            page_range: '1-2',
        },
        {
            invoice: {number: 'INV-2', issue_date: '2025-02-01', total: 200},
            supplier: {name: 'Supplier B'},
            lines: [{descripcion: 'Item 2', cantidad: 2, pvpunitario: 100}],
            taxes: [],
            confidence: {},
            warnings: [],
            page_range: '3',
        },
        {
            invoice: {number: 'INV-3', issue_date: '2025-03-01', total: 50},
            supplier: {name: 'Supplier A'},
            lines: [],
            taxes: [],
            confidence: {},
            warnings: [],
            page_range: '4',
        },
    ];

    hooks.handleMultiInvoiceResponse(hooks.state.documents[0], invoices);

    assert.equal(hooks.state.documents.length, 3);
    assert.equal(hooks.state.documents[0].extractedData.invoice.number, 'INV-1');
    assert.equal(hooks.state.documents[1].extractedData.invoice.number, 'INV-2');
    assert.equal(hooks.state.documents[2].extractedData.invoice.number, 'INV-3');

    // All share the same tmpFile (same source document)
    assert.equal(hooks.state.documents[1].tmpFile, 'aiscan_batch_abc123.pdf');
    assert.equal(hooks.state.documents[2].tmpFile, 'aiscan_batch_abc123.pdf');

    // Original doc is updated in-place (first invoice)
    assert.ok(hooks.state.documents[0]._multiInvoiceSource);
    assert.ok(hooks.state.documents[1]._multiInvoiceSource);

    // Each document should have a valid status (not analyzing)
    for (const doc of hooks.state.documents) {
        assert.notEqual(doc.status, 'analyzing');
    }
});

test('handleMultiInvoiceResponse re-indexes documents correctly', () => {
    const {hooks} = loadTestHooks();

    hooks.state.documents = [
        {
            index: 0,
            file: null,
            originalName: 'first.pdf',
            mimeType: 'application/pdf',
            size: 500,
            objectUrl: 'blob:test1',
            tmpFile: 'aiscan_first_001.pdf',
            status: 'analyzed',
            extractedData: {invoice: {number: 'EXISTING'}},
            error: null,
            reviewDecision: null,
        },
        {
            index: 1,
            file: null,
            originalName: 'multi.pdf',
            mimeType: 'application/pdf',
            size: 1000,
            objectUrl: 'blob:test2',
            tmpFile: 'aiscan_multi_002.pdf',
            status: 'analyzing',
            extractedData: null,
            error: null,
            reviewDecision: null,
        },
    ];
    hooks.state.importMode = 'lines';

    const invoices = [
        {
            invoice: {number: 'M-1', issue_date: '2025-01-01', total: 50},
            supplier: {},
            lines: [],
            taxes: [],
            confidence: {},
            warnings: [],
        },
        {
            invoice: {number: 'M-2', issue_date: '2025-01-02', total: 75},
            supplier: {},
            lines: [],
            taxes: [],
            confidence: {},
            warnings: [],
        },
    ];

    hooks.handleMultiInvoiceResponse(hooks.state.documents[1], invoices);

    // Should now have 3 documents: first.pdf, multi.pdf [1/2], multi.pdf [2/2]
    assert.equal(hooks.state.documents.length, 3);
    assert.equal(hooks.state.documents[0].index, 0);
    assert.equal(hooks.state.documents[1].index, 1);
    assert.equal(hooks.state.documents[2].index, 2);

    // Original first document is untouched
    assert.equal(hooks.state.documents[0].extractedData.invoice.number, 'EXISTING');
});

test('getValidationWarnings drops stale total mismatch warnings after totals are corrected', () => {
    const {hooks} = loadTestHooks();

    const warnings = hooks.getValidationWarnings({
        invoice: {total: 21.30},
        lines: [
            {cantidad: 1, pvpunitario: 10, iva: 0, irpf: 0},
            {cantidad: 1, pvpunitario: 11.3, iva: 0, irpf: 0},
        ],
        _validation_errors: ['Duplicado detectado', 'aiscan-total-mismatch'],
        _total_mismatch_warning: 'aiscan-total-mismatch',
    });

    assert.deepEqual(warnings, ['Duplicado detectado']);
});

test('getValidationWarnings recalculates the current total mismatch warning', () => {
    const {hooks} = loadTestHooks();

    const warnings = hooks.getValidationWarnings({
        invoice: {total: 21.30},
        lines: [
            {cantidad: 1, pvpunitario: 10, iva: 0, irpf: 0},
            {cantidad: 1, pvpunitario: 15, iva: 0, irpf: 0},
        ],
        _validation_errors: [],
    });

    assert.deepEqual(warnings, ['aiscan-total-mismatch']);
});

test('buildPaymentMethodSelect renders options with correct selection', () => {
    const {hooks} = loadTestHooks();

    const html = hooks.buildPaymentMethodSelect('TRANS');

    assert.match(html, /value="TRANS".*selected/);
    assert.match(html, /value="CONT"/);
    assert.match(html, /value="TAR"/);
    assert.match(html, /id="invoice_codpago"/);
});

test('collectFormData captures codpago from DOM select element', () => {
    const {elements, hooks} = loadTestHooks();

    hooks.state.codpago = 'TRANS';

    // Simulate that the review panel has children (so collectFormData reads DOM)
    elements['aiscan-review'] = {
        id: 'aiscan-review',
        innerHTML: '',
        checked: false,
        disabled: false,
        indeterminate: false,
        value: '',
        children: {length: 1},
        querySelectorAll() { return []; },
        addEventListener() {},
        classList: { add() {}, remove() {}, toggle() {} },
    };

    // Set the payment method select value (simulating user selection)
    elements['invoice_codpago'] = {
        id: 'invoice_codpago',
        innerHTML: '',
        checked: false,
        disabled: false,
        indeterminate: false,
        value: 'TRANS',
        querySelectorAll() { return []; },
        addEventListener() {},
        classList: { add() {}, remove() {}, toggle() {} },
    };

    const baseData = {
        invoice: {number: 'F-001', issue_date: '2025-01-01'},
        supplier: {name: 'Test'},
        lines: [],
    };

    const result = hooks.collectFormData(baseData);

    assert.equal(result.invoice.codpago, 'TRANS');
});

test('state has codpago field for payment method tracking', () => {
    const {hooks} = loadTestHooks();

    assert.ok('codpago' in hooks.state);
});

test('resolveAutocompleteKeyAction moves the highlight with arrow keys', () => {
    const {hooks} = loadTestHooks();
    const normalize = value => JSON.parse(JSON.stringify(value));

    assert.deepEqual(
        normalize(hooks.resolveAutocompleteKeyAction('ArrowDown', true, -1, 3)),
        {type: 'highlight', index: 0, preventDefault: true}
    );
    assert.deepEqual(
        normalize(hooks.resolveAutocompleteKeyAction('ArrowDown', true, 0, 3)),
        {type: 'highlight', index: 1, preventDefault: true}
    );
    assert.deepEqual(
        normalize(hooks.resolveAutocompleteKeyAction('ArrowUp', true, 1, 3)),
        {type: 'highlight', index: 0, preventDefault: true}
    );
});

test('resolveAutocompleteKeyAction selects with tab and closes with escape', () => {
    const {hooks} = loadTestHooks();
    const normalize = value => JSON.parse(JSON.stringify(value));

    assert.deepEqual(
        normalize(hooks.resolveAutocompleteKeyAction('Tab', true, 1, 3)),
        {type: 'select', index: 1, moveFocus: true, preventDefault: true}
    );
    assert.deepEqual(
        normalize(hooks.resolveAutocompleteKeyAction('Enter', true, 0, 3)),
        {type: 'select', index: 0, moveFocus: false, preventDefault: true}
    );
    assert.deepEqual(
        normalize(hooks.resolveAutocompleteKeyAction('Escape', true, 0, 3)),
        {type: 'close', preventDefault: true}
    );
});

test('buildProductMatchBadge renders the unlinked state without a reference', () => {
    const {hooks} = loadTestHooks();
    const badge = hooks.buildProductMatchBadge('');
    assert.match(badge, /text-bg-secondary/);
    assert.match(badge, /fa-unlink/);
});

test('buildProductMatchBadge renders a green link for a real match', () => {
    const {hooks} = loadTestHooks();
    const badge = hooks.buildProductMatchBadge('REF-1');
    assert.match(badge, /text-bg-success/);
    assert.match(badge, /fa-link/);
    assert.match(badge, /REF-1/);
});

test('buildProductMatchBadge renders the history suggestion state', () => {
    const {hooks} = loadTestHooks();
    const badge = hooks.buildProductMatchBadge('REF-1', 'history');
    assert.match(badge, /text-bg-warning/);
    assert.match(badge, /fa-clock-rotate-left/);
    assert.match(badge, /REF-1/);
    assert.doesNotMatch(badge, /text-bg-success/);
});

// ── Tipo de tercero (proveedor / acreedor) ─────────────────────────────

test('normalizePartyType reconoce proveedor y acreedor en español e inglés', () => {
    const {hooks} = loadTestHooks();
    assert.equal(hooks.normalizePartyType('supplier'), hooks.PARTY_SUPPLIER);
    assert.equal(hooks.normalizePartyType('proveedor'), hooks.PARTY_SUPPLIER);
    assert.equal(hooks.normalizePartyType('creditor'), hooks.PARTY_CREDITOR);
    assert.equal(hooks.normalizePartyType('acreedor'), hooks.PARTY_CREDITOR);
    assert.equal(hooks.normalizePartyType('ACREEDOR'), hooks.PARTY_CREDITOR);
    assert.equal(hooks.normalizePartyType(''), hooks.PARTY_SUPPLIER);
});

test('applyPartyTypeToSupplier marca is_creditor al importar como acreedor', () => {
    const {hooks} = loadTestHooks();
    const supplier = hooks.applyPartyTypeToSupplier({name: 'Gestoría Demo'}, 'creditor');
    assert.equal(supplier.party_type, hooks.PARTY_CREDITOR);
    assert.equal(supplier.is_creditor, true);
    assert.equal(supplier.name, 'Gestoría Demo');
});

test('applyPartyTypeToSupplier marca is_creditor=false al importar como proveedor', () => {
    const {hooks} = loadTestHooks();
    const supplier = hooks.applyPartyTypeToSupplier({name: 'Almacén Demo', is_creditor: true}, 'supplier');
    assert.equal(supplier.party_type, hooks.PARTY_SUPPLIER);
    assert.equal(supplier.is_creditor, false);
});

test('finalizeAnalyzedDoc propaga el partyType del estado al proveedor extraído', () => {
    const {hooks} = loadTestHooks();
    hooks.state.partyType = hooks.PARTY_CREDITOR;
    const doc = {
        status: 'analyzing',
        extractedData: {
            invoice: {number: 'F-AC-1', total: 121},
            supplier: {name: 'Asesoría Legal SL', tax_id: 'B12345678'},
            lines: [{descripcion: 'Honorarios', cantidad: 1, pvpunitario: 100, iva: 21}],
            taxes: [],
            confidence: {},
            warnings: [],
        },
    };

    hooks.finalizeAnalyzedDoc(doc);

    assert.equal(doc.extractedData.supplier.party_type, hooks.PARTY_CREDITOR);
    assert.equal(doc.extractedData.supplier.is_creditor, true);
    assert.equal(doc._partyType, hooks.PARTY_CREDITOR);
});

test('resolveDocPartyType prioriza el tipo guardado en el documento sobre el estado global', () => {
    const {hooks} = loadTestHooks();
    hooks.state.partyType = hooks.PARTY_SUPPLIER;
    const doc = {
        extractedData: {
            supplier: {party_type: 'creditor', is_creditor: true},
        },
    };
    assert.equal(hooks.resolveDocPartyType(doc), hooks.PARTY_CREDITOR);
});

// ── Confianza visual (issue #56) ───────────────────────────────────────

test('resolveFieldConfidence fuerza 0% cuando el valor del CIF está vacío', () => {
    const {hooks} = loadTestHooks();
    assert.equal(hooks.resolveFieldConfidence('', 0.7), 0);
    assert.equal(hooks.resolveFieldConfidence('   ', 0.95), 0);
    assert.equal(hooks.resolveFieldConfidence(null, 0.7), 0);
});

test('resolveFieldConfidence conserva la confianza cuando hay CIF', () => {
    const {hooks} = loadTestHooks();
    assert.equal(hooks.resolveFieldConfidence('B12345678', 0.7), 0.7);
    assert.equal(hooks.resolveFieldConfidence('B12345678', 70), 0.7);
});

test('confidenceBadgeClass usa umbrales rojo <50, amarillo 50-80, verde >=80', () => {
    const {hooks} = loadTestHooks();
    assert.equal(hooks.confidenceBadgeClass(0), 'text-bg-danger');
    assert.equal(hooks.confidenceBadgeClass(0.49), 'text-bg-danger');
    assert.equal(hooks.confidenceBadgeClass(0.5), 'text-bg-warning');
    assert.equal(hooks.confidenceBadgeClass(0.7), 'text-bg-warning');
    assert.equal(hooks.confidenceBadgeClass(0.79), 'text-bg-warning');
    assert.equal(hooks.confidenceBadgeClass(0.8), 'text-bg-success');
    assert.equal(hooks.confidenceBadgeClass(0.99), 'text-bg-success');
});

test('buildConfidenceBadge muestra 0% rojo si no hay CIF aunque la IA diga 70%', () => {
    const {hooks} = loadTestHooks();
    const badge = hooks.buildConfidenceBadge('', 0.7);
    assert.match(badge, /text-bg-danger/);
    assert.match(badge, />0%</);
    assert.doesNotMatch(badge, /text-bg-success/);
    assert.doesNotMatch(badge, />70%</);
});

test('buildConfidenceBadge muestra 70% amarillo con valor presente', () => {
    const {hooks} = loadTestHooks();
    const badge = hooks.buildConfidenceBadge('B12345678', 0.7);
    assert.match(badge, /text-bg-warning/);
    assert.match(badge, />70%</);
});

test('finalizeAnalyzedDoc fuerza needs_review y confianza 0 si falta CIF', () => {
    const {hooks} = loadTestHooks();
    const doc = {
        status: 'analyzing',
        extractedData: {
            invoice: {number: 'F-1', total: 100},
            supplier: {name: 'Proveedor sin CIF', tax_id: ''},
            lines: [{descripcion: 'Servicio', cantidad: 1, pvpunitario: 100, iva: 0}],
            confidence: {supplier_tax_id: 0.7, supplier_name: 0.9},
            warnings: [],
        },
    };
    hooks.finalizeAnalyzedDoc(doc);
    assert.equal(doc.extractedData.confidence.supplier_tax_id, 0);
    assert.equal(doc.status, 'needs_review');
    assert.ok(
        (doc.extractedData._validation_errors || []).some(w => /cif|nif|tax/i.test(String(w)))
        || (doc.extractedData._validation_errors || []).length > 0
    );
});
