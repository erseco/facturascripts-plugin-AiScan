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
