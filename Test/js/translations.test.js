const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// Issue #147: el JS traduce con window.aiscanI18n, que se rellena en la vista.
// Una clave que falta ahí se pinta tal cual (pasó con aiscan-import-history).
test('every key the workflow JS translates is exported to window.aiscanI18n', () => {
    const root = path.join(__dirname, '..', '..');
    const fragmentsDir = path.join(root, 'Assets', 'JS', 'workflow');
    const js = fs.readdirSync(fragmentsDir)
        .filter(name => name.endsWith('.js'))
        .map(name => fs.readFileSync(path.join(fragmentsDir, name), 'utf8'))
        .join('\n');
    const view = fs.readFileSync(path.join(root, 'View', 'AiScanInvoice.html.twig'), 'utf8');
    const used = new Set([...js.matchAll(/trans\('([a-z0-9-]+)'\)|trans\('([a-z0-9-]+)',/g)]
        .map(match => match[1] || match[2])
        .filter(key => key.startsWith('aiscan-')));
    const exported = new Set([...view.matchAll(/^\s+'([a-z0-9_-]+)':/gm)].map(match => match[1]));

    const missing = [...used].filter(key => !exported.has(key)).sort();
    assert.deepEqual(missing, []);

    for (const status of ['pending', 'analyzing', 'analyzed', 'needs_review', 'discarded', 'ready', 'imported', 'failed']) {
        assert.ok(exported.has('aiscan-status-' + status), 'aiscan-status-' + status);
    }
});
