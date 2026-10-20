---
name: javascript-expert
description: JavaScript patterns for AiScan plugin. One IIFE bundle (aiscan-workflow.js) built from fragments in Assets/JS/workflow/ with `make js`, no ES modules, Node --test runner for tests.
---

# JavaScript Expert — AiScan Frontend Patterns

## Module Architecture

The review/import page loads **one script**, `Assets/JS/aiscan-workflow.js`, through
`AssetManager::addJs()` in `AiScanInvoice::loadPageAssets()`. The settings page loads
`Assets/JS/aiscan-config.js`. No bundler, no ES modules.

### aiscan-workflow.js is generated (issue #137)
- **Never edit `Assets/JS/aiscan-workflow.js` by hand.** Edit the fragments in
  `Assets/JS/workflow/` and run `make js` (plain `cat` in name order).
- The fragments are consecutive pieces of a single IIFE: `00-state.js` opens it and
  holds `state`/`STATUS`; `99-test-hooks.js` exposes `__aiscanWorkflowTestHooks` and
  closes it. Every other file is a group of functions in that shared scope, so any
  function can call any other; there is no import/export between fragments.
- Pick the fragment by area: `01-helpers` (trans, escaping, `apiFetch`,
  `readJsonResponse`, validation), `03-upload`, `04-analysis`, `05-review-sidebar`,
  `06-preview`, `07-review-panel`, `08-default-product`, `09-taxes-and-totals`,
  `10-line-products`, `11-lines-section`, `12-supplier`, `13-form-actions`,
  `14-import`, `15-ui-navigation`, `16-split-handle`.
- New fragments need a two-digit prefix that puts them in the right place; a
  fragment must not open or close the IIFE.
- `Test/js/bundle.test.js` fails when the bundle is out of date.
- `Assets/JS/workflow/` is `export-ignore`d: the release ZIP only ships the bundle.

### Network calls
- Use `apiFetch(url, options)` (adds the `multireqtoken`, #129) and parse with
  `readJsonResponse(response)` (treats 4xx/5xx as errors, #135). Never call
  `fetch` directly for `AiScanInvoice` actions.

## Coding Rules

### No Modern Module Syntax
```javascript
// WRONG — FacturaScripts loads scripts via <script> tags
import { foo } from './bar.js';
export function baz() {}

// CORRECT — plain functions inside the shared IIFE scope of the fragments
function renderSomething(doc) { ... }
```

### State Management
- State is a plain object — no class instances, no Proxy
- Deep clone via `JSON.parse(JSON.stringify(obj))` (see `collectFormData`)
- Never mutate function arguments — clone first, modify clone, return clone

### Event Handling
- Bind with `addEventListener` after each render, or delegate on a stable parent
  for dynamically inserted DOM elements
- Clean up handlers when modal closes to prevent leaks

### Error Handling
- Wrap `apiFetch` calls in `try/catch` (or `.catch()`)
- Show user-facing errors in the modal UI — never silent failures
- Console.log for debug info only when `debug_mode` is enabled

## Testing

- **Runner:** `node --test Test/js/*.test.js`
- **Assert:** Node.js built-in `assert` module (no mocha/jest)
- **Files:** `Test/js/aiscan-workflow.test.js` loads the bundle in a `vm` context with
  a minimal fake DOM and uses `__aiscanWorkflowTestHooks`; `Test/js/bundle.test.js`
  checks the bundle matches the fragments
- **Rule:** to test a new function, add it to the hooks in `99-test-hooks.js`
- **Rule:** Tests must run without browser environment (no jsdom)

## Reference Files
- `Assets/JS/workflow/` — source fragments of the review/import page
- `Assets/JS/aiscan-workflow.js` — generated bundle (`make js`)
- `Assets/JS/aiscan-config.js` — settings page
- `Test/js/` — JavaScript test files
