---
name: bootstrap-jquery-design
description: Bootstrap 5 and jQuery UI patterns for FacturaScripts. Modal 1200px, split-pane flexbox, wizard navigation, .aiscan-* CSS scoping, Font Awesome 6, button injection via addButton(), responsive breakpoints.
---

# Bootstrap & jQuery Design — AiScan UI Patterns

## Framework Stack

| Library | Version | Source | Notes |
|---|---|---|---|
| Bootstrap | 5.x | FacturaScripts core | Already loaded — do not import again |
| jQuery | 3.x | FacturaScripts core | Already loaded — use `$()` globally |
| Font Awesome | 6 (Solid) | FacturaScripts core | Use `fas fa-*` prefix |

**Rule:** Never add CDN links or bundle these libraries — they come from FacturaScripts core.

## CSS Scoping

All custom CSS classes use the `.aiscan-` prefix to avoid collisions with FacturaScripts core styles:

```css
.aiscan-modal-body { }
.aiscan-split-pane { }
.aiscan-drop-area { }
.aiscan-confidence-badge { }
```

**Rule:** Never override Bootstrap classes globally. Use `.aiscan-` scoped selectors or Bootstrap utility classes.

## Modal Design

```html
<div class="modal fade" id="aiscanModal" tabindex="-1">
  <div class="modal-dialog" style="max-width: 1200px;">
    <div class="modal-content">
      <div class="modal-header">...</div>
      <div class="modal-body p-0"><!-- zero padding for split-pane --></div>
      <div class="modal-footer">...</div>
    </div>
  </div>
</div>
```

- **Max width:** 1200px (`style` or custom class, not `.modal-xl` which is 1140px)
- **Body padding:** 0 — the split-pane fills the entire body
- **Backdrop:** default Bootstrap (click outside to close)
- See `usability-accessibility` skill for ARIA requirements

## Split-Pane Layout

```css
.aiscan-split-pane {
    display: flex;
    height: 70vh;
}
.aiscan-split-left {
    flex: 0 0 55%;     /* Document preview */
    overflow: auto;
}
.aiscan-split-right {
    flex: 0 0 45%;     /* Extracted data */
    overflow: auto;
}
.aiscan-split-divider {
    width: 4px;
    cursor: col-resize;
    background: var(--bs-border-color);
}
```

- Divider is draggable via JS (mousedown/mousemove)
- **Responsive:** below 768px, stack vertically (preview on top)

```css
@media (max-width: 767.98px) {
    .aiscan-split-pane { flex-direction: column; }
    .aiscan-split-left,
    .aiscan-split-right { flex: none; width: 100%; }
}
```

## Wizard Navigation

```html
<div class="aiscan-wizard-nav d-flex justify-content-between align-items-center p-2">
  <button class="btn btn-secondary btn-sm" id="aiscan-prev">
    <i class="fas fa-chevron-left"></i> Anterior
  </button>
  <span class="aiscan-wizard-step">Archivo 1 de 3</span>
  <button class="btn btn-primary btn-sm" id="aiscan-next">
    Siguiente <i class="fas fa-chevron-right"></i>
  </button>
</div>
```

- Previous/Next disabled when at boundaries (see `aiscan-flow.js` `getWizardMeta()`)
- Primary action button changes label contextually (Analizar / Guardar / Siguiente)
- Step indicator: "Archivo X de N"

## Drop Area

```css
.aiscan-drop-area {
    border: 2px dashed var(--bs-border-color);
    border-radius: 0.5rem;
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s, background-color 0.2s;
}
.aiscan-drop-area:hover,
.aiscan-drop-area.dragover {
    border-color: var(--bs-primary);
    background-color: rgba(var(--bs-primary-rgb), 0.05);
}
```

- Accepts: PDF, JPG, JPEG, PNG, WebP
- Visual feedback on dragover (border color change + background tint)
- Click triggers hidden `<input type="file">`
- Multi-file supported

## Buttons

### Toolbar Injection (Extension)
Buttons are injected into FacturaScripts toolbar via the Extension pattern:

```php
// In Extension/Controller/EditFacturaProveedor.php
$this->addButton('footer', [
    'action' => 'scan-invoice',
    'icon' => 'fas fa-camera',
    'label' => 'scan-invoice',
    'type' => 'action',
]);
```

### Button Styles
- **Primary action:** `btn btn-primary` (Guardar, Analizar)
- **Secondary action:** `btn btn-secondary` (Cancelar, Anterior)
- **Danger action:** `btn btn-danger` (Eliminar linea)
- **Small buttons:** add `btn-sm` inside modal context
- **Icon + text:** `<i class="fas fa-save"></i> Guardar` — always include text, not icon-only

## Tables (Line Items)

```html
<table class="table table-hover table-sm">
  <thead class="table-light">
    <tr>
      <th>Descripcion</th>
      <th class="text-end">Cantidad</th>
      <th class="text-end">Precio</th>
      <th class="text-end">Dto%</th>
      <th class="text-end">IVA%</th>
      <th class="text-end">Total</th>
      <th></th><!-- Actions -->
    </tr>
  </thead>
</table>
```

- Numeric columns: `text-end` alignment
- Editable fields: `<input class="form-control form-control-sm">`
- Action column: icon buttons (edit, delete) with `btn-sm`

## Icons (Font Awesome 6 Solid)

| Action | Icon |
|---|---|
| Scan/Camera | `fas fa-camera` |
| Upload | `fas fa-upload` |
| Analyze/AI | `fas fa-brain` |
| Save | `fas fa-save` |
| Delete | `fas fa-trash` |
| Edit | `fas fa-pen` |
| Settings | `fas fa-cog` |
| Success | `fas fa-check-circle` |
| Warning | `fas fa-exclamation-triangle` |
| Error | `fas fa-times-circle` |
| Spinner | `fas fa-spinner fa-spin` |

## Reference Files
- `Assets/CSS/aiscan.css` — All custom styles
- `Assets/JS/aiscan.js` — UI controller with DOM manipulation
- `Extension/Controller/EditFacturaProveedor.php` — Button injection
- `Extension/Controller/ListFacturaProveedor.php` — List view button
