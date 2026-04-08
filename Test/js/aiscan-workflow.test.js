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
            getElementById(id) {
                return getElement(id);
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
