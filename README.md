# AiScan

AiScan is a production-oriented FacturaScripts plugin that adds AI-assisted supplier invoice scanning to the purchase invoice workflow.

## Features

- Adds a **Scan Invoice** button to `EditFacturaProveedor`
- Uploads PDF, JPG, JPEG, PNG and WebP invoices with size validation
- Shows the uploaded document in a large side-by-side review modal
- Extracts supplier, invoice header, taxes, summary and line items with AI
- Validates the AI response with strict schema checks and normalization
- Matches suppliers by tax ID and name
- Lets the user confirm creation of a missing supplier with prefilled data
- Matches products by SKU/reference when possible and falls back to free-text lines
- Creates or completes the purchase invoice and attaches the original source file
- Supports OpenAI, Google Gemini, Mistral and generic OpenAI-compatible APIs
- Exposes an experimental Browser Prompt API option that is shown as available only when the browser supports it

## Plugin settings

The plugin configuration page is available through **AiScan Configuration** and stores settings in the `AiScan` settings group.

Available settings include:

- Enable/disable plugin
- Default AI provider
- Maximum upload size
- Request timeout
- Allowed file extensions
- Auto-analyze after upload
- Debug mode
- Provider credentials and model names for:
  - OpenAI
  - Google Gemini
  - Mistral
  - Generic OpenAI-compatible endpoints
- Experimental Browser Prompt API toggle

## Purchase invoice flow

1. Open or create a purchase invoice in FacturaScripts.
2. Click **Scan Invoice**.
3. Upload the PDF or image invoice.
4. Review the preview and extracted data in the modal side panel.
5. Adjust supplier, invoice fields and line items if needed.
6. If the supplier does not exist, confirm creating it.
7. Save to create or update the purchase invoice.
8. AiScan attaches the original file to the saved invoice using FacturaScripts native attachment models.

## Development

### Docker environment

```bash
make upd
```

Open `http://localhost:8080` and log in with:

- User: `admin`
- Password: `admin`

### Useful commands

- `make lint`
- `make test`
- `make format`
- `make rebuild`
- `make package VERSION=1.0.0`

## Testing and CI

The repository includes:

- Docker-based local validation
- GitHub Actions CI for lint and plugin tests
- Release packaging workflow

## Packaging

Build a distributable ZIP with:

```bash
make package VERSION=1.0.0
```

The generated package is written to `dist/`.
