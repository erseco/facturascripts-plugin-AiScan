const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

test('aiscan.js includes an inline flow fallback when AiScanFlow is unavailable', () => {
    const source = fs.readFileSync(
        path.resolve(__dirname, '../../Assets/JS/aiscan.js'),
        'utf8'
    );

    assert.match(source, /const flow = window\.AiScanFlow \|\| createFlowFallback\(\);/);
    assert.match(source, /function createFlowFallback\(\)/);
    assert.match(source, /normalizeUploadResponse\(response\)/);
});
