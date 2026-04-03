# ADR-0002: Dedicated Purchase Import Workflow

## Status

Accepted

## Date

2026-04-02

## Context

The AiScan plugin was originally designed as a modal-based workflow triggered from the purchase invoice edit/list screens via Extension controllers. Users had to navigate to an existing invoice or the invoice list, click "Scan Invoice", and work within a Bootstrap modal. This approach had several limitations:

- The modal was attached to existing invoice screens, not a first-class entry point.
- Multi-file batch import was awkward within a modal context.
- There was no way to select import modes (by lines vs by total).
- No support for historical invoice context during AI analysis.
- No supplier-to-default-product mapping for recurring total-only imports.
- The workflow forced linear processing — a single file failure could block the batch.

The goal was to make AiScan a dedicated purchase-import module under the Purchases menu.

## Decision

Transform the AiScan plugin from a modal-based workflow into a full-page, multi-step purchase import workflow accessible as its own menu entry under Purchases.

### Architecture changes

1. **Controller refactored**: `AiScanInvoice` now serves both HTML (via `$this->view()`) and JSON API (via action parameter). Set `showonmenu = true` to appear in the Purchases menu.

2. **New Twig template**: `View/AiScanInvoice.html.twig` extends `Master/MenuBghTemplate.html.twig` with a 3-step workflow (Upload → Review → Import).

3. **New JavaScript**: `Assets/JS/aiscan-workflow.js` replaces the old modal-based `aiscan.js` + `aiscan-flow.js` with a full-page SPA-like workflow managing document state, batch processing, and split-pane review.

4. **Import modes**: Two modes added — "by lines" (extract individual line items) and "by total" (single line with invoice totals). Mode selection happens at upload time and affects both the AI extraction prompt and the invoice creation logic.

5. **Supplier default product**: New `AiScanSupplierProduct` model (`aiscan_supplier_products` table) stores a default product reference per supplier, used automatically in total-mode imports.

6. **Historical context**: New `HistoricalContextService` fetches up to 5 previous invoices from the matched supplier and formats them as bounded structured hints for the AI prompt.

7. **Extensions simplified**: `EditFacturaProveedor` and `ListFacturaProveedor` extensions reduced from asset-loading + modal-triggering to simple link buttons pointing to the AiScan page.

8. **ExtractionService extended**: New mode-specific prompt hints (MODE_LINES_HINT, MODE_TOTAL_HINT) and historical context injection in the execution prompt. `extractPdfText` made public static to eliminate duplication with the controller.

9. **InvoiceMapper extended**: New `buildTotalModeLine` and `buildLinesMode` methods. Shared helpers extracted: `computeTaxRate`, `fallbackDescription`, `fallbackSubtotal`.

### Files added

| File | Purpose |
|------|---------|
| `View/AiScanInvoice.html.twig` | Full-page 3-step workflow template |
| `Assets/JS/aiscan-workflow.js` | SPA-like workflow (upload/review/import) |
| `Model/AiScanSupplierProduct.php` | Supplier default product mapping |
| `Table/aiscan_supplier_products.xml` | DB schema for mapping |
| `Lib/HistoricalContextService.php` | Previous invoice context builder |
| `docker/setup-aiscan.php` | Docker auto-setup (API keys from env vars) |

### Files removed

| File | Reason |
|------|--------|
| `Assets/JS/aiscan.js` | Replaced by aiscan-workflow.js |
| `Assets/JS/aiscan-flow.js` | Logic merged into aiscan-workflow.js |

## Consequences

### Positive

- AiScan is now a first-class menu entry under Purchases, not a hidden modal.
- Batch upload with per-document status tracking (pending/analyzing/analyzed/discarded/ready/imported/failed).
- Import mode selection gives operators control over how invoices are created.
- Supplier default product mapping simplifies recurring total-only imports.
- Historical context improves AI extraction accuracy for known suppliers.
- Errors are isolated per document — one failure does not block the batch.
- Extensions still provide convenient links from invoice screens.

### Negative

- The old modal-based workflow is gone. Users familiar with the old flow need to adapt.
- The full-page approach requires a dedicated browser tab instead of a quick overlay.

### Neutral

- The FacturaScripts `Controller::view()` API is used instead of `setTemplate()` (which does not exist in FS 2025+).
- Plugin settings are auto-configured from environment variables in Docker, but this is a dev convenience, not a production requirement.
