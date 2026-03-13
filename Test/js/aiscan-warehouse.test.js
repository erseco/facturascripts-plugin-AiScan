const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

test('aiscan.js requires warehouse input when creating a new invoice', () => {
    const source = fs.readFileSync(
        path.resolve(__dirname, '../../Assets/JS/aiscan.js'),
        'utf8'
    );

    assert.match(source, /invoice_warehouse_code/);
    assert.match(source, /trans\('aiscan-warehouse'\)/);
    assert.match(source, /required aria-required="true"/);
    assert.match(source, /if \(!flow\.getSaveInvoiceId\(entry\) && !data\.invoice\.warehouse_code\)/);
});
