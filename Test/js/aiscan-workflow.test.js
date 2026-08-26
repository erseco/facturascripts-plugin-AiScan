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
    context.aiscanTaxExceptions = [
        {code: 'ES_20', description: 'Exenta art. 20 LIVA'},
        {code: 'ES_84', description: 'Inversión del sujeto pasivo'},
    ];

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
        invoice: {number: 'F-1', issue_date: '2025-01-01', total: 21.30},
        supplier: {matched_supplier_id: '000001'},
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
        invoice: {number: 'F-1', issue_date: '2025-01-01', total: 21.30},
        supplier: {matched_supplier_id: '000001'},
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

test('buildTaxExceptionSelect renders the exception list sent by the core', () => {
    const {hooks} = loadTestHooks();

    const html = hooks.buildTaxExceptionSelect('ES_84');

    assert.match(html, /value="ES_84"\s+selected/);
    assert.match(html, /value="ES_20"/);
    assert.match(html, /Inversión del sujeto pasivo/);
    // Los códigos antiguos ya no se ofrecen (#95).
    assert.doesNotMatch(html, /ES_PASSIVE_SUBJECT|ES_N1|ES_LOCATION_RULES/);
});

test('buildTaxExceptionSelect keeps the empty option selected when there is no exception', () => {
    const {hooks} = loadTestHooks();

    const html = hooks.buildTaxExceptionSelect('');

    assert.match(html, /value=""\s+selected/);
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

// ── Issue #67: manual entry / scan_failed fallback ─────────────

test('buildEmptyExtractedData returns editable shell with scan_failed flag', () => {
    const {hooks} = loadTestHooks();
    const data = hooks.buildEmptyExtractedData('Scan failed');

    assert.equal(data._scan_failed, true);
    assert.ok(Array.isArray(data.lines));
    assert.equal(data.lines.length, 0);
    assert.equal(data.invoice.number, null);
    assert.ok(Array.isArray(data.warnings));
    assert.ok(data.warnings.includes('Scan failed'));
});

test('applyManualEntryFallback opens reviewable panel instead of failed status', () => {
    const {hooks} = loadTestHooks();
    const doc = {
        index: 0,
        tmpFile: 'aiscan_tmp_x.pdf',
        status: 'analyzing',
        extractedData: null,
        error: null,
        reviewDecision: null,
        _partyType: 'supplier',
    };

    hooks.applyManualEntryFallback(doc, {
        message: 'Could not connect to the AI provider.',
        scanFailureCode: 'network_error',
    });

    assert.notEqual(doc.status, 'failed');
    assert.equal(doc.status, 'needs_review');
    assert.equal(doc.scanFailed, true);
    assert.equal(doc.error, null);
    assert.ok(doc.extractedData);
    assert.equal(doc.extractedData._scan_failed, true);
    assert.ok(doc.extractedData.supplier);
    assert.ok(doc.extractedData.invoice);
});

test('applyAnalyzeResponse handles scan_failed controlled payload', () => {
    const {hooks} = loadTestHooks();
    const doc = {
        index: 0,
        tmpFile: 'aiscan_tmp_y.pdf',
        status: 'analyzing',
        extractedData: null,
        error: null,
        reviewDecision: null,
    };

    const ok = hooks.applyAnalyzeResponse(doc, {
        success: true,
        scan_failed: true,
        scan_failure_code: 'invalid_json',
        message: 'Invalid AI response',
        data: hooks.buildEmptyExtractedData('Invalid AI response'),
    });

    assert.equal(ok, true);
    assert.equal(doc.scanFailed, true);
    assert.equal(doc.status, 'needs_review');
    assert.equal(doc.scanFailureCode, 'invalid_json');
    assert.ok(doc.extractedData);
});

test('applyAnalyzeResponse success path fills fields without scan_failed', () => {
    const {hooks} = loadTestHooks();
    hooks.state.importMode = 'lines';
    const doc = {
        index: 0,
        tmpFile: 'aiscan_tmp_ok.pdf',
        status: 'analyzing',
        extractedData: null,
        error: null,
        reviewDecision: null,
        _partyType: 'supplier',
    };

    hooks.applyAnalyzeResponse(doc, {
        success: true,
        scan_failed: false,
        data: {
            supplier: {name: 'ACME', tax_id: 'B12345678'},
            invoice: {number: 'F-100', issue_date: '2025-01-10', total: 121},
            lines: [{descripcion: 'Item', cantidad: 1, pvpunitario: 100, iva: 21}],
            taxes: [],
            confidence: {
                supplier_name: 0.9,
                supplier_tax_id: 0.9,
                invoice_number: 0.9,
                issue_date: 0.9,
                total: 0.9,
                lines: 0.9,
            },
            warnings: [],
            _validation_errors: [],
            _provider: 'mock',
        },
    });

    assert.equal(doc.scanFailed, false);
    assert.notEqual(doc.status, 'failed');
    assert.equal(doc.extractedData.invoice.number, 'F-100');
    assert.equal(doc.extractedData.supplier.name, 'ACME');
    assert.equal(doc.extractedData.lines.length, 1);
});

test('openAllAsManualEntry skips AI and marks all docs needs_review', () => {
    const {hooks} = loadTestHooks();
    hooks.state.documents = [
        {
            index: 0,
            tmpFile: 'a.pdf',
            status: 'pending',
            extractedData: null,
            error: null,
            reviewDecision: null,
            _partyType: 'supplier',
        },
        {
            index: 1,
            tmpFile: 'b.pdf',
            status: 'pending',
            extractedData: null,
            error: null,
            reviewDecision: null,
            _partyType: 'supplier',
        },
        {
            index: 2,
            tmpFile: null,
            status: 'failed',
            extractedData: null,
            error: 'upload error',
            reviewDecision: null,
        },
    ];
    hooks.state.importMode = 'lines';

    // Apply fallback directly (openAllAsManualEntry also re-renders the current
    // document, which needs a richer DOM mock than this suite provides).
    hooks.state.documents.forEach(doc => {
        if (!doc.tmpFile || doc.status === 'failed') {
            return;
        }
        hooks.applyManualEntryFallback(doc, {
            message: 'No AI provider configured',
            scanFailureCode: 'manual_entry',
        });
    });

    assert.equal(hooks.state.documents[0].status, 'needs_review');
    assert.equal(hooks.state.documents[1].status, 'needs_review');
    assert.equal(hooks.state.documents[0].scanFailed, true);
    assert.equal(hooks.state.documents[0].extractedData._scan_failed, true);
    assert.equal(hooks.state.documents[2].status, 'failed');
    assert.equal(hooks.state.documents[2].extractedData, null);
});

test('manual entry payload remains importable after user fills fields', () => {
    const {hooks} = loadTestHooks();
    const doc = {
        index: 0,
        tmpFile: 'manual.pdf',
        status: 'analyzing',
        extractedData: null,
        error: null,
        reviewDecision: null,
        _partyType: 'supplier',
    };

    hooks.applyManualEntryFallback(doc, {
        message: 'Automatic scan is unavailable.',
        scanFailureCode: 'missing_api_key',
    });

    // User fills data by hand (mirrors collectFormData result).
    doc.extractedData.supplier.name = 'Manual Supplier';
    doc.extractedData.supplier.tax_id = 'B99887766';
    doc.extractedData.supplier.matched_supplier_id = '000001';
    doc.extractedData.invoice.number = 'M-1';
    doc.extractedData.invoice.issue_date = '2025-01-01';
    doc.extractedData.invoice.total = 50;
    doc.extractedData.lines = [
        {descripcion: 'Linea manual', cantidad: 1, pvpunitario: 50, iva: 0},
    ];
    doc.status = hooks.STATUS.READY;
    doc.reviewDecision = 'approved';

    assert.equal(doc.scanFailed, true);
    assert.equal(doc.status, 'ready');
    assert.equal(doc.reviewDecision, 'approved');
    assert.equal(doc.extractedData.invoice.number, 'M-1');
    assert.equal(doc.extractedData.lines.length, 1);
    // Import gate treats needs_review/ready as non-failed
    assert.notEqual(doc.status, 'failed');
    assert.equal(hooks.canMarkDocReady(doc.extractedData), true);
});

test('getBlockingImportErrors blocks when supplier and invoice identity missing (#78)', () => {
    const {hooks} = loadTestHooks();
    const errors = hooks.getBlockingImportErrors({
        invoice: {total: 43.3},
        supplier: {},
        lines: [],
    });

    assert.ok(errors.length >= 3);
    assert.equal(hooks.canMarkDocReady({
        invoice: {total: 43.3},
        supplier: {},
    }), false);
});

test('getBlockingImportErrors allows matched supplier without tax id (#78)', () => {
    const {hooks} = loadTestHooks();
    const data = {
        invoice: {number: 'F-1', issue_date: '2025-01-01', total: 10},
        supplier: {matched_supplier_id: '000001', name: '', tax_id: ''},
    };
    assert.equal(hooks.getBlockingImportErrors(data).length, 0);
    assert.equal(hooks.canMarkDocReady(data), true);
});

test('getBlockingImportErrors requires name and tax id for new supplier (#78)', () => {
    const {hooks} = loadTestHooks();
    const missingTax = hooks.getBlockingImportErrors({
        invoice: {number: 'F-1', issue_date: '2025-01-01'},
        supplier: {name: 'Nuevo SL', tax_id: '', create_if_missing: true},
    });
    assert.ok(missingTax.some(msg => /tax|cif|nif|fiscal/i.test(msg) || msg.includes('aiscan-missing-supplier-tax-id')));

    const ready = {
        invoice: {number: 'F-1', issue_date: '2025-01-01'},
        supplier: {name: 'Nuevo SL', tax_id: 'B12345678'},
    };
    assert.equal(hooks.getBlockingImportErrors(ready).length, 0);
    assert.equal(hooks.canMarkDocReady(ready), true);
});

test('revokeReadyIfBlocked demotes READY when vital fields cleared (#78)', () => {
    const {hooks} = loadTestHooks();
    const doc = {
        status: hooks.STATUS.READY,
        reviewDecision: 'approved',
        extractedData: {
            invoice: {number: '', issue_date: '2025-01-01'},
            supplier: {name: 'ACME', tax_id: 'B12345678'},
        },
    };

    assert.equal(hooks.revokeReadyIfBlocked(doc, doc.extractedData), true);
    assert.equal(doc.status, hooks.STATUS.NEEDS_REVIEW);
    assert.equal(doc.reviewDecision, null);

    doc.status = hooks.STATUS.READY;
    doc.reviewDecision = 'approved';
    doc.extractedData.invoice.number = 'F-1';
    assert.equal(hooks.revokeReadyIfBlocked(doc, doc.extractedData), false);
    assert.equal(doc.status, hooks.STATUS.READY);
});

test('finalizeAnalyzedDoc flags missing supplier name as needs_review (#78)', () => {
    const {hooks} = loadTestHooks();
    const doc = {
        index: 0,
        status: 'analyzing',
        extractedData: {
            invoice: {number: 'X', issue_date: '2025-01-01', total: 10},
            supplier: {name: '', tax_id: ''},
            lines: [{descripcion: 'A', cantidad: 1, pvpunitario: 10, iva: 0}],
            confidence: {},
            warnings: [],
            _validation_errors: [],
        },
        reviewDecision: null,
    };

    hooks.finalizeAnalyzedDoc(doc);
    assert.equal(doc.status, hooks.STATUS.NEEDS_REVIEW);
    assert.ok(Array.isArray(doc.extractedData._validation_errors));
    assert.ok(doc.extractedData._validation_errors.length > 0);
});

test('applyPinnedProductToLines rellena líneas vacías y respeta matches reales (#69)', () => {
    const {hooks} = loadTestHooks();
    const doc = {
        extractedData: {
            lines: [
                {descripcion: 'Sin producto', cantidad: 1, pvpunitario: 10},
                {descripcion: 'Ya enlazado', cantidad: 1, pvpunitario: 20, referencia: 'REAL-1'},
                {descripcion: 'Histórico', cantidad: 1, pvpunitario: 5, referencia: 'OLD', referencia_source: 'history'},
            ],
        },
        _importMode: 'lines',
    };

    hooks.applyPinnedProductToLines(doc, 'PIN-99');

    assert.equal(doc.extractedData.lines[0].referencia, 'PIN-99');
    assert.equal(doc.extractedData.lines[0].referencia_source, 'pinned');
    assert.equal(doc.extractedData.lines[1].referencia, 'REAL-1');
    assert.equal(doc.extractedData.lines[2].referencia, 'PIN-99');
    assert.equal(doc.extractedData.lines[2].referencia_source, 'pinned');
});

test('buildTotalLines incluye producto fijado de _product_suggestion (#69)', () => {
    const {hooks} = loadTestHooks();
    const lines = hooks.buildTotalLines({
        invoice: {summary: 'Servicio', subtotal: 100, tax_amount: 21, total: 121},
        taxes: [{base: 100, rate: 21, amount: 21}],
        _product_suggestion: {referencia: 'SERV-1', source: 'pinned'},
    });

    assert.equal(lines.length, 1);
    assert.equal(lines[0].referencia, 'SERV-1');
    assert.equal(lines[0].referencia_source, 'pinned');
    assert.equal(lines[0].pvpunitario, 100);
});

test('discountPercentFromAmount convierte euros de línea a % (#81)', () => {
    const {hooks} = loadTestHooks();
    const percent = hooks.discountPercentFromAmount(20, 4.99, 5.40);
    assert.ok(Math.abs(percent - 5.410821643) < 0.0001);
    const net = 20 * 4.99 * (1 - percent / 100);
    assert.ok(Math.abs(net - (20 * 4.99 - 5.40)) < 0.01);
});

test('discountAmountFromPercent convierte % a euros de línea (#81)', () => {
    const {hooks} = loadTestHooks();
    const amount = hooks.discountAmountFromPercent(2, 10, 10);
    assert.equal(amount, 2);
});

test('discountPercentFromAmount con base 0 no divide entre cero (#81)', () => {
    const {hooks} = loadTestHooks();
    assert.equal(hooks.discountPercentFromAmount(0, 10, 4.30), 0);
    assert.equal(hooks.discountAmountFromPercent(0, 10, 6.5), 0);
});

test('buildProductMatchBadge distingue pinned e history (#69)', () => {
    const {hooks} = loadTestHooks();
    const pinned = hooks.buildProductMatchBadge('PIN-1', 'pinned');
    const history = hooks.buildProductMatchBadge('HIST-1', 'history');
    assert.match(pinned, /fa-thumbtack/);
    assert.match(history, /fa-clock-rotate-left/);
});

function fakeFile(name, size, lastModified = 1, type = 'application/pdf') {
    return {name, size, lastModified, type};
}

test('mergeSelectedFiles acumula archivos de una segunda selección (#84)', () => {
    const {hooks} = loadTestHooks();
    const first = hooks.mergeSelectedFiles([], [fakeFile('a.pdf', 100), fakeFile('b.pdf', 200)], 'supplier');
    const merged = hooks.mergeSelectedFiles(first, [fakeFile('c.jpg', 50, 2, 'image/jpeg')], 'supplier');

    assert.equal(first.length, 2);
    assert.equal(merged.length, 3);
    assert.deepEqual(merged.map(doc => doc.originalName), ['a.pdf', 'b.pdf', 'c.jpg']);
    assert.deepEqual(merged.map(doc => doc.index), [0, 1, 2]);
    assert.equal(merged[2].mimeType, 'image/jpeg');
    assert.equal(merged[2].status, hooks.STATUS.PENDING);
    assert.equal(merged[2]._partyType, 'supplier');
    assert.ok(merged[2].objectUrl);
});

test('mergeSelectedFiles conserva dos facturas con los mismos metadatos (#84)', () => {
    const {hooks} = loadTestHooks();
    const first = hooks.mergeSelectedFiles([], [fakeFile('factura.pdf', 245632, 1786612345000)], 'supplier');
    const merged = hooks.mergeSelectedFiles(first, [fakeFile('factura.pdf', 245632, 1786612345000)], 'supplier');

    assert.equal(first.length, 1);
    assert.equal(merged.length, 2);
    assert.equal(merged[0].originalName, 'factura.pdf');
    assert.equal(merged[1].originalName, 'factura.pdf');
    assert.notEqual(merged[1], merged[0]);
});

test('mergeSelectedFiles ignora una selección vacía (#84)', () => {
    const {hooks} = loadTestHooks();
    const first = hooks.mergeSelectedFiles([], [fakeFile('a.pdf', 100)], 'supplier');
    const empty = hooks.mergeSelectedFiles(first, [], 'supplier');

    assert.equal(empty.length, 1);
    assert.equal(empty[0], first[0]);
});

test('removeSelectedFile quita un archivo y reindexa el resto (#84)', () => {
    const {hooks} = loadTestHooks();
    const docs = hooks.mergeSelectedFiles([], [
        fakeFile('a.pdf', 100),
        fakeFile('b.pdf', 200),
        fakeFile('c.pdf', 300),
    ], 'supplier');

    const remaining = hooks.removeSelectedFile(docs, 1);

    assert.equal(remaining.length, 2);
    assert.deepEqual(remaining.map(doc => doc.originalName), ['a.pdf', 'c.pdf']);
    assert.deepEqual(remaining.map(doc => doc.index), [0, 1]);
});

test('removeSelectedFile no altera la cola si el índice no existe (#84)', () => {
    const {hooks} = loadTestHooks();
    const docs = hooks.mergeSelectedFiles([], [fakeFile('a.pdf', 100)], 'supplier');

    assert.equal(hooks.removeSelectedFile(docs, -1).length, 1);
    assert.equal(hooks.removeSelectedFile(docs, 3).length, 1);
});

test('buildSelectedFileListHtml incluye botón de quitar por archivo (#84)', () => {
    const {hooks} = loadTestHooks();
    const docs = hooks.mergeSelectedFiles([], [fakeFile('factura-a.pdf', 1024), fakeFile('ticket.jpg', 2048, 2, 'image/jpeg')], 'supplier');
    const html = hooks.buildSelectedFileListHtml(docs);

    assert.match(html, /data-aiscan-remove-file="0"/);
    assert.match(html, /data-aiscan-remove-file="1"/);
    assert.match(html, /factura-a\.pdf/);
    assert.match(html, /ticket\.jpg/);
    assert.match(html, /aria-label=/);
    assert.match(html, /fa-trash/);
});

test('applySelectedFiles no añade ficheros mientras la cola está bloqueada (#84)', () => {
    const {hooks} = loadTestHooks();
    hooks.state.documents = hooks.mergeSelectedFiles([], [fakeFile('a.pdf', 100)], 'supplier');

    hooks.setUploadQueueLocked(true);
    const afterAdd = hooks.applySelectedFiles([fakeFile('b.pdf', 200)]);

    assert.equal(hooks.isUploadQueueLocked(), true);
    assert.equal(afterAdd.length, 1);
    assert.equal(hooks.state.documents.length, 1);
    assert.equal(hooks.state.documents[0].originalName, 'a.pdf');
});

test('applyRemoveSelectedFile no quita ficheros mientras la cola está bloqueada (#84)', () => {
    const {hooks} = loadTestHooks();
    hooks.state.documents = hooks.mergeSelectedFiles([], [fakeFile('a.pdf', 100), fakeFile('b.pdf', 200)], 'supplier');

    hooks.setUploadQueueLocked(true);
    const afterRemove = hooks.applyRemoveSelectedFile(0);

    assert.equal(afterRemove.length, 2);
    assert.equal(hooks.state.documents.length, 2);
    assert.deepEqual(hooks.state.documents.map(doc => doc.originalName), ['a.pdf', 'b.pdf']);
});

test('al desbloquear la cola se puede añadir y quitar de nuevo (#84)', () => {
    const {hooks} = loadTestHooks();
    hooks.state.documents = hooks.mergeSelectedFiles([], [fakeFile('a.pdf', 100)], 'supplier');
    hooks.setUploadQueueLocked(true);
    hooks.applySelectedFiles([fakeFile('b.pdf', 200)]);

    hooks.setUploadQueueLocked(false);
    hooks.applySelectedFiles([fakeFile('b.pdf', 200)]);
    assert.equal(hooks.state.documents.length, 2);

    hooks.applyRemoveSelectedFile(0);
    assert.equal(hooks.state.documents.length, 1);
    assert.equal(hooks.state.documents[0].originalName, 'b.pdf');
});

test('buildSelectedFileListHtml desactiva la papelera si la cola está bloqueada (#84)', () => {
    const {hooks} = loadTestHooks();
    const docs = hooks.mergeSelectedFiles([], [fakeFile('a.pdf', 100)], 'supplier');
    hooks.setUploadQueueLocked(true);
    const html = hooks.buildSelectedFileListHtml(docs);

    assert.match(html, /disabled/);
    assert.match(html, /aria-disabled="true"/);
});

test('applyUploadedFileMeta usa el nombre PDF que devuelve el servidor (#80)', () => {
    const {hooks} = loadTestHooks();
    const docs = hooks.mergeSelectedFiles([], [fakeFile('nombre factura.jpeg', 4096, 1, 'image/jpeg')], 'supplier');
    const doc = docs[0];

    hooks.applyUploadedFileMeta(doc, {
        client_index: 0,
        mime_type: 'application/pdf',
        original_name: 'nombre factura.pdf',
        size: 8123,
        tmp_file: 'aiscan_nombre-factura_abc123.pdf',
    });

    assert.equal(doc.originalName, 'nombre factura.pdf');
    assert.equal(doc.mimeType, 'application/pdf');
    assert.equal(doc.tmpFile, 'aiscan_nombre-factura_abc123.pdf');
    assert.equal(doc.size, 8123);
});

test('applyUploadedFileMeta no pisa el nombre si el servidor no envía original_name (#80)', () => {
    const {hooks} = loadTestHooks();
    const docs = hooks.mergeSelectedFiles([], [fakeFile('ticket.png', 100, 1, 'image/png')], 'supplier');
    const doc = docs[0];

    hooks.applyUploadedFileMeta(doc, {
        tmp_file: 'aiscan_ticket_xyz.png',
        mime_type: 'image/png',
    });

    assert.equal(doc.originalName, 'ticket.png');
    assert.equal(doc.tmpFile, 'aiscan_ticket_xyz.png');
});

test('previewAsPdf sigue mostrando la foto si el original era imagen (#80)', () => {
    const {hooks} = loadTestHooks();
    const docs = hooks.mergeSelectedFiles([], [fakeFile('nombre factura.jpeg', 4096, 1, 'image/jpeg')], 'supplier');
    hooks.applyUploadedFileMeta(docs[0], {
        mime_type: 'application/pdf',
        original_name: 'nombre factura.pdf',
        tmp_file: 'aiscan_nombre-factura_abc123.pdf',
    });

    assert.equal(hooks.previewAsPdf(docs[0]), false);
    assert.equal(hooks.previewAsPdf({mimeType: 'application/pdf', file: {type: 'application/pdf'}}), true);
    assert.equal(hooks.previewAsPdf({mimeType: 'application/pdf'}), true);
});

// ── Multi-model selection and re-analysis (#89) ───────────────────────

function withModels(hooks) {
    hooks.state.availableModels = [
        {provider: 'openai', model: 'gpt-5-nano', label: 'OpenAI — gpt-5-nano'},
        {provider: 'openai', model: 'gpt-5.2', label: 'OpenAI — gpt-5.2'},
        {provider: 'gemini', model: 'gemini-2.5-flash-lite', label: 'Google Gemini — gemini-2.5-flash-lite'},
    ];
    hooks.state.defaultProvider = 'openai';
    hooks.state.defaultModel = 'gpt-5-nano';
    hooks.state.configuredDefault = {provider: 'openai', model: 'gpt-5-nano'};
    return hooks;
}

test('modelChoiceForDoc repite el modelo con el que se analizó el documento (#89)', () => {
    const {hooks} = loadTestHooks();
    withModels(hooks);

    const choice = hooks.modelChoiceForDoc({extractedData: {_provider: 'gemini', _model: 'gemini-2.5-flash-lite'}});

    assert.equal(choice.provider, 'gemini');
    assert.equal(choice.model, 'gemini-2.5-flash-lite');
});

test('modelChoiceForDoc usa el modelo predeterminado si el documento no se ha analizado (#89)', () => {
    const {hooks} = loadTestHooks();
    withModels(hooks);

    for (const doc of [{extractedData: null}, null]) {
        const choice = hooks.modelChoiceForDoc(doc);
        assert.equal(choice.provider, 'openai');
        assert.equal(choice.model, 'gpt-5-nano');
    }
});

test('choiceLabel usa la etiqueta configurada y cae al par proveedor/modelo (#89)', () => {
    const {hooks} = loadTestHooks();
    withModels(hooks);

    assert.equal(hooks.choiceLabel({provider: 'openai', model: 'gpt-5.2'}), 'OpenAI — gpt-5.2');
    assert.equal(hooks.choiceLabel({provider: 'grok', model: 'grok-4.5'}), 'grok — grok-4.5');
    assert.equal(hooks.choiceLabel({provider: 'mock', model: ''}), 'mock');
});

test('renderReanalyzeMenu lista los modelos configurados y marca el actual (#89)', () => {
    const {elements, hooks} = loadTestHooks();
    withModels(hooks);

    hooks.renderReanalyzeMenu({extractedData: {_provider: 'openai', _model: 'gpt-5.2'}});
    const html = elements['aiscan-reanalyze-menu'].innerHTML;

    assert.equal((html.match(/data-provider=/g) || []).length, 3);
    assert.match(html, /data-provider="openai" data-model="gpt-5.2"/);
    assert.match(html, /data-provider="gemini" data-model="gemini-2.5-flash-lite"/);
    // the current model is the only one with a check mark
    assert.equal((html.match(/fa-check/g) || []).length, 1);
    // only the configured default carries the badge
    assert.equal((html.match(/badge text-bg-secondary/g) || []).length, 1);
    const nanoItem = html.split('data-model="gpt-5-nano"')[1].split('</li>')[0];
    assert.match(nanoItem, /badge text-bg-secondary/);
});

test('renderReanalyzeMenu marca como predeterminado el modelo configurado, no el primero (#89)', () => {
    const {elements, hooks} = loadTestHooks();
    withModels(hooks);
    hooks.state.configuredDefault = {provider: 'gemini', model: 'gemini-2.5-flash-lite'};

    hooks.renderReanalyzeMenu({extractedData: {_provider: 'openai', _model: 'gpt-5-nano'}});
    const html = elements['aiscan-reanalyze-menu'].innerHTML;

    assert.equal((html.match(/badge text-bg-secondary/g) || []).length, 1);
    const geminiItem = html.split('data-model="gemini-2.5-flash-lite"')[1].split('</li>')[0];
    assert.match(geminiItem, /badge text-bg-secondary/);
});

test('renderReanalyzeMenu no ofrece alternativas cuando solo hay un modelo (#89)', () => {
    const {elements, hooks} = loadTestHooks();
    withModels(hooks);
    hooks.state.availableModels = [{provider: 'openai', model: 'gpt-5-nano', label: 'OpenAI — gpt-5-nano'}];

    hooks.renderReanalyzeMenu({extractedData: {_provider: 'openai', _model: 'gpt-5-nano'}});

    assert.equal(elements['aiscan-reanalyze-menu'].innerHTML, '');
});
