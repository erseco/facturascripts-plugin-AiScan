const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadTestHooks() {
    const scriptPath = path.join(__dirname, '..', '..', 'Assets', 'JS', 'aiscan-workflow.js');
    const script = fs.readFileSync(scriptPath, 'utf8');

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
            createElement() {
                return {
                    innerHTML: '',
                    set textContent(value) {
                        this.innerHTML = value == null ? '' : String(value);
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

    return context.__aiscanWorkflowTestHooks;
}

test('applySelectionRange selects the visible range between anchor and target', () => {
    const {applySelectionRange} = loadTestHooks();
    const selected = new Set([4]);
    const sorted = [10, 4, 7, 2, 9];

    const usedRange = applySelectionRange(selected, sorted, 4, 2, true);

    assert.equal(usedRange, true);
    assert.deepEqual([...selected].sort((a, b) => a - b), [2, 4, 7]);
});

test('applySelectionRange clears the visible range when unchecking with Shift', () => {
    const {applySelectionRange} = loadTestHooks();
    const selected = new Set([10, 4, 7, 2, 9]);
    const sorted = [10, 4, 7, 2, 9];

    const usedRange = applySelectionRange(selected, sorted, 4, 2, false);

    assert.equal(usedRange, true);
    assert.deepEqual([...selected].sort((a, b) => a - b), [9, 10]);
});

test('applySelectionRange ignores unknown anchors without mutating the selection', () => {
    const {applySelectionRange} = loadTestHooks();
    const selected = new Set([9]);
    const sorted = [10, 4, 7, 2, 9];

    const usedRange = applySelectionRange(selected, sorted, 3, 2, true);

    assert.equal(usedRange, false);
    assert.deepEqual([...selected], [9]);
});
