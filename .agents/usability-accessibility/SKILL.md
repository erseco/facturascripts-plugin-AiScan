---
name: usability-accessibility
description: Usability and WCAG 2.1 AA accessibility for AiScan modal UI. ARIA attributes, keyboard navigation, focus management, color contrast, screen reader support, form validation feedback.
---

# Usability & Accessibility — AiScan UI Guidelines

## Target Standard

**WCAG 2.1 Level AA** — all new and modified UI must meet this baseline.

## Modal Accessibility

The AiScan scanning modal is the primary UI surface. Requirements:

### ARIA Attributes
```html
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="aiscan-modal-title">
  <h5 id="aiscan-modal-title">Escanear Factura</h5>
  ...
</div>
```

### Focus Management
- On modal open: move focus to the first interactive element (file input or close button)
- **Focus trap**: Tab key cycles within modal — never escapes to background content
- On modal close: return focus to the button that opened it
- Use `tabindex="-1"` on non-interactive containers that receive programmatic focus

### Keyboard Navigation
| Key | Action |
|---|---|
| `Escape` | Close modal |
| `Enter` | Activate primary action button |
| `Tab` / `Shift+Tab` | Navigate between interactive elements |
| Arrow keys | Navigate within radio groups, select menus |

## Wizard Steps

- Mark current step with `aria-current="step"`
- Announce step changes with `aria-live="polite"` region
- Previous/Next buttons clearly labeled (not just arrows)
- Disabled nav buttons use `aria-disabled="true"` + `tabindex="-1"`

## Async Status Feedback

AI analysis takes seconds — user must know what's happening:

```html
<div aria-live="polite" aria-atomic="true" class="aiscan-status">
  Analizando documento... <!-- screen readers announce changes -->
</div>
```

- Use `aria-live="polite"` for non-urgent updates (analysis progress)
- Use `aria-live="assertive"` for errors only
- Include a visible spinner/progress indicator (not just text)

## Confidence Indicators

Extracted data includes confidence scores. Display rules:

- **Never use color alone** — always pair with text or icon
  - Good: green checkmark + "Alta confianza"
  - Bad: just a green dot
- Minimum contrast: text on colored background must meet 4.5:1 ratio
- Provide tooltip/title with numeric confidence value

## Forms & Validation

### Labels
- Every `<input>`, `<select>`, `<textarea>` must have an associated `<label>`
- Use `for="id"` attribute — not wrapping `<label>` (FS convention)
- Placeholder text is NOT a substitute for a label

### Error Messages
```html
<input id="invoice-number" aria-describedby="invoice-number-error" aria-invalid="true">
<div id="invoice-number-error" class="text-danger" role="alert">
  Numero de factura requerido
</div>
```

- Link errors to inputs via `aria-describedby`
- Set `aria-invalid="true"` on invalid fields
- Error messages must be visible (not just color change on border)

## File Upload

- Drag-and-drop area must have a keyboard-accessible alternative (upload button)
- Drop area: `role="button"` with `tabindex="0"` and `keydown` handler for Enter/Space
- Announce successful upload: "Archivo subido: factura.pdf"
- File type restrictions shown visually AND in `accept` attribute

## Split-Pane Layout

The modal uses a 55/45 split (preview left, data right):

- **Tab order**: left pane (document preview) → right pane (extracted data) — logical reading order
- Draggable divider must be keyboard-accessible (`role="separator"`, arrow keys resize)
- On mobile (<768px): stack vertically, preview on top

## Tables (Line Items)

```html
<table class="table" aria-label="Lineas de factura">
  <thead>
    <tr><th scope="col">Descripcion</th><th scope="col">Cantidad</th>...</tr>
  </thead>
  ...
</table>
```

- Use `<th scope="col">` for column headers
- Editable cells: use `<input>` inside `<td>`, each with label (visible or `aria-label`)
- Add/remove row buttons: descriptive labels ("Eliminar linea 3")

## Color & Contrast

- **Text on background**: minimum 4.5:1 contrast ratio
- **UI components** (buttons, inputs, icons): minimum 3:1
- **Do not convey information by color alone** — always pair with shape, text, or pattern
- Test with browser DevTools accessibility audit

## Reference Files
- `Assets/JS/aiscan.js` — UI event handling and DOM manipulation
- `Assets/CSS/aiscan.css` — Modal, split-pane, and form styling
