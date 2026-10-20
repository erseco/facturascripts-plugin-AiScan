const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// Issue #137: aiscan-workflow.js se genera concatenando Assets/JS/workflow/*.js
// (`make js`). Si alguien edita un fragmento y no lo regenera, o edita el bundle
// a mano, el navegador y los tests cargarían código distinto del fuente.
test('aiscan-workflow.js is the concatenation of Assets/JS/workflow fragments (run `make js`)', () => {
    const jsDir = path.join(__dirname, '..', '..', 'Assets', 'JS');
    const fragmentsDir = path.join(jsDir, 'workflow');
    const fragments = fs.readdirSync(fragmentsDir).filter(name => name.endsWith('.js')).sort();
    const built = fragments.map(name => fs.readFileSync(path.join(fragmentsDir, name), 'utf8')).join('');
    const bundle = fs.readFileSync(path.join(jsDir, 'aiscan-workflow.js'), 'utf8');

    assert.ok(fragments.length > 1);
    assert.ok(built === bundle, 'Assets/JS/aiscan-workflow.js is out of date: run `make js`');
});
