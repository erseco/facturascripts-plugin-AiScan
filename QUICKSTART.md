# Quick Start

## 1. Start FacturaScripts

```bash
make upd
```

## 2. Open the app

Go to `http://localhost:8080` and log in with:

- **Username:** `admin`
- **Password:** `admin`

## 3. Enable AiScan

Enable the plugin from **Admin Panel → Plugins**.

## 4. Configure an AI provider

Open **AiScan Configuration** and set at least one provider credential:

- OpenAI
- Google Gemini
- Mistral
- OpenAI-compatible endpoint

## 5. Scan a supplier invoice

1. Open a purchase invoice.
2. Click **Scan Invoice**.
3. Upload a PDF or image invoice.
4. Review the extracted data.
5. Save the purchase invoice and attached source file.

## 6. Stop the environment

```bash
make down
```
