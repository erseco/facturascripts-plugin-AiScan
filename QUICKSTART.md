# Quick Start

## 1. Start FacturaScripts

```bash
make upd
```

The stack seeds a **Canary Islands** demo company (IGIC 7% by default, Spanish PGC
accounting plan, sample suppliers/products) from `blueprint.json` so purchase invoices
can be imported and posted with accounting entries without manual Wizard steps.

## 2. Open the app

Go to `http://localhost:8080` (or `http://localhost:18080` if you use the committed
`docker-compose.override.yml`) and log in with:

- **Username:** `admin`
- **Password:** `admin`

## 3. Enable AiScan

Enable the plugin from **Admin Panel → Plugins** (already enabled in the Docker auto-setup).

## 4. Configure an AI provider

Open **AiScan Configuration** and set at least one provider credential:

- OpenAI
- Google Gemini
- Mistral
- OpenAI-compatible endpoint

Or export keys before `make up` (`GEMINI_API_KEY`, `OPENAI_API_KEY`, …) so
`docker/setup-aiscan.php` stores them on boot.

### Optional: debug / mock mode (no API keys)

1. In **AiScan Configuration**, enable **debug mode** (`debug_mode`).
2. In **Compras → AiScan**, choose provider **`mock`**.
3. Pick a fixture (or click **next fixture**) and upload any sample file from `Test/fixtures/`.
4. Analyze: the response comes from `Test/fixtures/responses/*.json` (no AI call).

Useful to verify the review UI and supplier default product suggestions without a live model.

## 5. Scan a supplier invoice

1. Open **Compras → AiScan** (or a purchase invoice and **Scan Invoice**).
2. Upload a PDF or image from `Test/fixtures/` (many are IGIC Canary invoices).
3. Review the extracted data (tax codes such as `IGIC7` / `IGIC0` should match the seeded taxes).
4. In the import summary, optionally enable **Update stock and purchase data from invoice lines**.
5. Save the purchase invoice and attached source file; the accounting entry should include IGIC.

## 6. Browser playground

[Try AiScan in FacturaScripts Playground](https://erseco.github.io/facturascripts-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Ferseco%2Ffacturascripts-plugin-AiScan%2Frefs%2Fheads%2Fmain%2Fblueprint.json)
using the same `blueprint.json` (`install` + `settings` + seed).

## 7. Stop the environment

```bash
make down
```
