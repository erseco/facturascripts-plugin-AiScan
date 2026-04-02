---
name: javascript-expert
description: JavaScript patterns for AiScan plugin. UMD modules (aiscan-flow.js), IIFE pattern (aiscan.js), jQuery integration, no bundler, no ES modules, Node --test runner for tests.
---

# JavaScript Expert — AiScan Frontend Patterns

## Module Architecture

AiScan uses **two JS files** loaded via `<script>` tags — no bundler, no ES modules.

### aiscan-flow.js (UMD Library)
- **Path:** `Assets/JS/aiscan-flow.js`
- **Pattern:** UMD (Universal Module Definition) — works in browser and Node.js
- **Purpose:** Pure state management functions, zero DOM dependency
- **Exports:** `normalizeUploadResponse`, `createWizardEntries`, `getWizardMeta`, `markEntrySaved`, `getSaveInvoiceId`, `cloneValue`
- **Rule:** No jQuery, no `document`, no `window` references (except UMD boilerplate)

### aiscan.js (IIFE UI Controller)
- **Path:** `Assets/JS/aiscan.js`
- **Pattern:** IIFE (Immediately Invoked Function Expression)
- **Purpose:** DOM interaction, event handling, AJAX calls, modal lifecycle
- **Dependencies:** jQuery (global `$`), aiscan-flow.js (global `AiScanFlow`)
- **Rule:** All DOM access goes here, never in aiscan-flow.js

## Coding Rules

### No Modern Module Syntax
```javascript
// WRONG — FacturaScripts loads scripts via <script> tags
import { foo } from './bar.js';
export function baz() {}

// CORRECT — UMD or IIFE patterns
(function(root, factory) {
    if (typeof module === 'object') module.exports = factory();
    else root.MyLib = factory();
})(typeof self !== 'undefined' ? self : this, function() { ... });
```

### jQuery for DOM and AJAX
```javascript
// Use jQuery — consistent with FacturaScripts core
$.ajax({ url: actionUrl, method: 'POST', data: formData });
$(document).on('click', '.aiscan-btn', handler);

// NOT vanilla fetch/addEventListener (compatibility)
```

### State Management
- State is a plain object — no class instances, no Proxy
- Deep clone via `cloneValue(obj)` → `JSON.parse(JSON.stringify(obj))`
- Never mutate function arguments — clone first, modify clone, return clone

### Event Handling
- Use **event delegation** via `$(document).on(event, selector, handler)`
- Keeps handlers working for dynamically inserted DOM elements
- Clean up handlers when modal closes to prevent leaks

### Error Handling
- Wrap AJAX calls in `.fail()` handlers
- Show user-facing errors in the modal UI — never silent failures
- Console.log for debug info only when `debug_mode` is enabled

## Testing

- **Runner:** `node --test Test/js/*.test.js`
- **Assert:** Node.js built-in `assert` module (no mocha/jest)
- **Files:** `Test/js/aiscan-flow.test.js`, `Test/js/aiscan-fallback.test.js`
- **Coverage:** Test pure functions from aiscan-flow.js; IIFE logic tested via fallback tests
- **Rule:** Tests must run without browser environment (no jsdom)

## Reference Files
- `Assets/JS/aiscan-flow.js` — UMD state management library
- `Assets/JS/aiscan.js` — IIFE UI controller
- `Test/js/` — JavaScript test files
