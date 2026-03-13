const test = require('node:test');
const assert = require('node:assert/strict');

const flow = require('../../Assets/JS/aiscan-flow.js');

test('single upload keeps single-file behavior', () => {
    const uploads = flow.normalizeUploadResponse({
        mime_type: 'application/pdf',
        original_name: 'invoice.pdf',
        size: 123,
        tmp_file: 'tmp-1.pdf',
    });
    const entries = flow.createWizardEntries(uploads, '42');
    const meta = flow.getWizardMeta(entries, 0);

    assert.equal(entries.length, 1);
    assert.equal(entries[0].invoiceId, '42');
    assert.equal(meta.isMultiFile, false);
    assert.equal(meta.primaryAction, 'save');
    assert.equal(meta.canGoBack, false);
    assert.equal(meta.canGoNext, false);
});

test('multiple upload enables wizard navigation', () => {
    const entries = flow.createWizardEntries([
        {client_index: 0, mime_type: 'application/pdf', original_name: 'one.pdf', size: 1, tmp_file: 'tmp-1.pdf'},
        {client_index: 1, mime_type: 'application/pdf', original_name: 'two.pdf', size: 2, tmp_file: 'tmp-2.pdf'},
        {client_index: 2, mime_type: 'image/png', original_name: 'three.png', size: 3, tmp_file: 'tmp-3.png'},
    ], '');

    const firstMeta = flow.getWizardMeta(entries, 0);
    const middleMeta = flow.getWizardMeta(entries, 1);
    const lastMeta = flow.getWizardMeta(entries, 2);

    assert.equal(firstMeta.isMultiFile, true);
    assert.equal(firstMeta.currentPosition, 1);
    assert.equal(firstMeta.total, 3);
    assert.equal(firstMeta.canGoBack, false);
    assert.equal(firstMeta.canGoNext, true);
    assert.equal(firstMeta.primaryAction, 'saveAndNext');

    assert.equal(middleMeta.canGoBack, true);
    assert.equal(middleMeta.canGoNext, true);
    assert.equal(lastMeta.canGoNext, false);
    assert.equal(lastMeta.primaryAction, 'finish');
});

test('state stays isolated per file', () => {
    const entries = flow.createWizardEntries([
        {client_index: 0, mime_type: 'application/pdf', original_name: 'one.pdf', size: 1, tmp_file: 'tmp-1.pdf'},
        {client_index: 1, mime_type: 'application/pdf', original_name: 'two.pdf', size: 2, tmp_file: 'tmp-2.pdf'},
    ], '');

    entries[0].extractedData = flow.cloneValue({
        _validation_errors: ['missing tax id'],
        invoice: {number: 'INV-001'},
        supplier: {name: 'First Supplier'},
    });
    entries[1].extractedData = flow.cloneValue({
        _validation_errors: [],
        invoice: {number: 'INV-002'},
        supplier: {name: 'Second Supplier'},
    });

    entries[0].extractedData.invoice.number = 'UPDATED-001';
    entries[0].extractedData._validation_errors.push('missing total');

    assert.equal(entries[1].extractedData.invoice.number, 'INV-002');
    assert.deepEqual(entries[1].extractedData._validation_errors, []);
    assert.equal(entries[1].extractedData.supplier.name, 'Second Supplier');
});

test('saved entries reuse their invoice id to prevent duplicate creates', () => {
    const [entry] = flow.createWizardEntries([
        {client_index: 0, mime_type: 'application/pdf', original_name: 'one.pdf', size: 1, tmp_file: 'tmp-1.pdf'},
    ], '');

    assert.equal(flow.getSaveInvoiceId(entry), '');

    const savedEntry = flow.markEntrySaved(entry, 91);
    assert.equal(flow.getSaveInvoiceId(savedEntry), '91');
    assert.equal(savedEntry.isSaved, true);

    const savedAgain = flow.markEntrySaved(savedEntry);
    assert.equal(flow.getSaveInvoiceId(savedAgain), '91');
    assert.equal(savedAgain.isSaved, true);
});
