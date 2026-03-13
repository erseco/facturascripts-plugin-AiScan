const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

test('aiscan.js includes drawer integration and page mapping hooks', () => {
    const source = fs.readFileSync(
        path.resolve(__dirname, '../../Assets/JS/aiscan.js'),
        'utf8'
    );

    assert.match(source, /AiScanInvoice\?action=create-supplier/);
    assert.match(source, /action: 'search-products'/);
    assert.match(source, /selected_reference/);
    assert.match(source, /aiscan-drawer/);
    assert.match(source, /purchasesFormLines/);
    assert.match(source, /findSupplierModal/);
});
