---
name: ai-generative
description: AI provider integration for invoice scanning. ProviderInterface contract, OpenAI/Gemini/Mistral implementations, extraction prompts, schema validation, confidence scoring, PDF/image processing, base64 encoding.
---

# AI & Generative — AiScan Provider Integration

## Provider Architecture

All AI providers implement `ProviderInterface`:

```php
interface ProviderInterface {
    public function getName(): string;
    public function isAvailable(): bool;
    public function analyzeDocument(
        string $content,
        string $mimeType,
        string $prompt,
        string $systemPrompt
    ): string;
}
```

### Available Providers

| Provider | Class | Default Model | API |
|---|---|---|---|
| OpenAI | `OpenAIProvider` | `gpt-5-mini` | Chat Completions |
| Gemini | `GeminiProvider` | `gemini-2.5-flash` | GenerateContent |
| Mistral | `MistralProvider` | `mistral-small-latest` | Chat Completions |
| Custom | `OpenAICompatibleProvider` | User-configured | OpenAI-compatible |

### Adding a New Provider
1. Create `Lib/Provider/{Name}Provider.php` implementing `ProviderInterface`
2. Register in `ExtractionService::getProvider()` switch
3. Add settings keys in `AiScanSettings::getDefaults()` (`{name}_api_key`, `{name}_model`)
4. Add UI group in `XMLView/AiScanConfig.xml`
5. Add translations in `Translation/*.json`

## Extraction Flow

```
File upload → MIME detection → Content encoding → Prompt assembly → API call → JSON parse → Schema validation → Supplier matching → Return
```

### Content Encoding by Type

| MIME Type | Encoding | Method |
|---|---|---|
| `image/jpeg`, `image/png`, `image/webp` | Base64 data URI | `data:{mime};base64,{content}` |
| `application/pdf` | Text extraction first | `pdftotext` via shell, fallback to base64 |
| `application/octet-stream` | Detected by extension | Route to image or PDF handling |

### PDF Processing Strategy
1. **Try `pdftotext`** — fast, lightweight, works for text-based PDFs
2. **If no text extracted** — fall back to base64 encoding (scanned/image PDFs)
3. **Text is injected** into the execution prompt as additional context
4. Provider receives both: extracted text (if any) + base64 document

## Prompt System

### System Prompt
- Stored in `Settings('AiScan', 'extraction_prompt')`
- Customizable by user via AiScanConfig panel
- Defines the expected JSON schema for extraction output
- Default loaded from `ExtractionService::getDefaultSystemPrompt()`

### Execution Prompt
- Built per-request by `ExtractionService`
- Injects placeholders: `{{FILE_NAME}}`, `{{MIME_TYPE}}`
- Includes extracted PDF text when available
- Instructions to return **only JSON**, no markdown wrapping

## Extraction Schema

The AI must return this JSON structure:

```json
{
  "document_type": "invoice|receipt|proforma|credit_note|unknown",
  "supplier": {
    "name": "string",
    "tax_id": "string",
    "email": "string|null",
    "phone": "string|null",
    "website": "string|null",
    "address": "string|null"
  },
  "customer": { "name": "string", "tax_id": "string", "address": "string|null" },
  "invoice": {
    "number": "string (required)",
    "issue_date": "YYYY-MM-DD (required)",
    "due_date": "YYYY-MM-DD|null",
    "currency": "ISO 4217 3-letter",
    "subtotal": "number",
    "tax_amount": "number",
    "withholding_amount": "number",
    "total": "number (required)",
    "summary": "string|null",
    "payment_terms": "string|null"
  },
  "taxes": [{ "name": "string", "rate": "number", "base": "number", "amount": "number" }],
  "lines": [{
    "description": "string",
    "quantity": "number",
    "unit_price": "number",
    "discount": "number",
    "tax_rate": "number",
    "line_total": "number",
    "sku": "string|null"
  }],
  "confidence": {
    "supplier_name": "0.0-1.0",
    "supplier_tax_id": "0.0-1.0",
    "invoice_number": "0.0-1.0",
    "issue_date": "0.0-1.0",
    "total": "0.0-1.0",
    "lines": "0.0-1.0"
  },
  "warnings": ["string"]
}
```

## Schema Validation (SchemaValidator)

`SchemaValidator::validate()` checks and normalizes:

### Required Fields
- `invoice.number`, `invoice.issue_date`, `invoice.total`

### Normalizations
| Input Format | Normalized To |
|---|---|
| `31/12/2024`, `12-31-2024`, `31.12.2024` | `2024-12-31` |
| `1.234,56` (European) | `1234.56` |
| `1,234.56` (US) | `1234.56` |
| `€`, `$`, `£`, `¥` | `EUR`, `USD`, `GBP`, `JPY` |

### Arithmetic Validation
- `subtotal + tax_amount - withholding_amount ≈ total` (tolerance for rounding)
- Warns but does not reject if mismatch (AI may have OCR errors)

## Confidence Scoring

Per-field confidence (0.0 to 1.0):
- **> 0.8**: High confidence — auto-fill, minimal review needed
- **0.5 - 0.8**: Medium — show with warning, suggest manual verification
- **< 0.5**: Low — highlight as uncertain, require manual input

Display rules: see `usability-accessibility` skill for visual requirements.

## Security Considerations

- **Never execute** content extracted from documents (no `eval`, no SQL from extracted data)
- **Validate JSON strictly** — `json_decode` with error checking, reject non-JSON responses
- **Sanitize file paths** — prevent directory traversal in temp file handling
- **API keys** — stored in FS Settings (encrypted at rest), never logged or exposed in responses
- **Rate limiting** — respect provider rate limits, use configurable `request_timeout`
- **Debug mode** — logs raw API responses to `AiScanLog` only when enabled

## Reference Files
- `Lib/Provider/ProviderInterface.php` — Provider contract
- `Lib/Provider/OpenAIProvider.php` — OpenAI implementation
- `Lib/Provider/GeminiProvider.php` — Gemini implementation
- `Lib/Provider/MistralProvider.php` — Mistral implementation
- `Lib/Provider/OpenAICompatibleProvider.php` — Custom endpoint
- `Lib/ExtractionService.php` — Extraction orchestration
- `Lib/SchemaValidator.php` — Schema validation and normalization
- `Lib/AiScanSettings.php` — Settings with provider defaults
