# AGENTS.md — Canonical Agent Instructions

This is the authoritative instruction file for all coding agents working in this repository.
All other agent files (`CLAUDE.md`, `GEMINI.md`, `.github/copilot-instructions.md`) refer here.

---

## Project Overview

**AiScan** is a FacturaScripts plugin that scans supplier invoices using AI (OpenAI, Google Gemini,
Mistral, or any OpenAI-compatible endpoint) and maps the extracted data into a purchase invoice.
Compatibility: **FacturaScripts 2025+**, **PHP 8.1+**, **PSR-12**.

---

## Agent Skills

Detailed instructions are organized by domain in `.agents/`. Load the relevant skill for your task:

| Skill | When to activate |
|---|---|
| [php-expert](.agents/php-expert/SKILL.md) | Writing or reviewing PHP code, fixing lint violations |
| [javascript-expert](.agents/javascript-expert/SKILL.md) | Working with JS files in Assets/JS/ or Test/js/ |
| [facturascripts-plugin](.agents/facturascripts-plugin/SKILL.md) | Controllers, extensions, models, XMLViews, Init.php |
| [usability-accessibility](.agents/usability-accessibility/SKILL.md) | Modal UI, forms, keyboard navigation, ARIA |
| [devops-testing](.agents/devops-testing/SKILL.md) | Docker, CI, tests, Makefile, packaging |
| [ai-generative](.agents/ai-generative/SKILL.md) | AI providers, extraction prompts, schema validation |
| [bootstrap-jquery-design](.agents/bootstrap-jquery-design/SKILL.md) | CSS, Bootstrap components, jQuery UI patterns |
| [documentation-adr](.agents/documentation-adr/SKILL.md) | ADRs, changelog, README/QUICKSTART updates |

---

## Before Changing Code

Inspect these files before making assumptions:

| File | Purpose |
|---|---|
| `facturascripts.ini` | Plugin name, version, min FacturaScripts version, min PHP |
| `phpcs.xml` | PHPCS ruleset (PSR-12 + extras, 120-char line limit) |
| `.php-cs-fixer.php` | PHP CS Fixer config (PSR-12, short arrays, single quotes) |
| `Makefile` | All development commands |
| `.github/workflows/ci.yml` | CI pipeline (Docker lint+test, then PHP matrix 8.1-8.5) |
| `Test/main/` | Unit tests |
| `Lib/` | Core business logic (matchers, mappers, services) |
| `docs/adr/` | Architecture Decision Records |

---

## Validation Workflow

Run these commands in order after making changes:

```bash
make format   # PHP CS Fixer – auto-fixes style issues
make lint     # PHPCS – reports remaining violations (must be clean)
make test     # PHPUnit – all tests must pass
```

All three require Docker (`make upd` starts the container automatically).

---

## End-to-end validation (real AI extraction → purchase invoice)

Unit tests cover the pipeline with recorded JSON responses (`Test/fixtures/responses/`)
but never call a real provider. To validate the whole flow (upload → AI extraction →
review → import) against a live model, the dev `docker-compose.yml` already enables the
plugin, seeds demo data (`blueprint.json`) and reads provider keys from the host
environment.

1. Export a provider key on the host **before** `make up`, e.g. `export GEMINI_API_KEY=…`
   (the container's `docker/setup-aiscan.php` writes it into the AiScan settings on boot).
2. `make up` — starts FacturaScripts + MariaDB. Open the app and log in (`admin`/`admin`).
   - Port: `http://localhost:8080` by default; the committed `docker-compose.override.yml`
     remaps it to **`http://localhost:18080`** (check `docker compose ps`).
3. Select the provider in **Compras → AiScan** ("Proveedor de IA"), or set it once:
   `default_provider=gemini` in the `AiScan` Settings (then clear the cache).
4. Drop a sample invoice from `Test/fixtures/` (PDF/JPG/PNG) on the upload zone, pick
   **Factura detallada**, analyze, review and import. Verify:
   - a `FacturaProveedor` is created with the right supplier, number and total;
   - the source file is attached (**Archivos** tab) and the image/PDF opens via the
     private `myft` URL (this is the JPG/JPEG path hardened in the attachment flow);
   - with **"Actualizar stock y datos de compra"** enabled, stock moves for linked
     products and per-line warnings (localized) are shown for the rest;
   - the keyboard navigation works on the review-screen product autocomplete
     (↑/↓ to highlight, Enter/Tab to select, Esc to close).

`Test/fixtures/` ships anonymized sample invoices (e.g. `F-2025-004…007`) covering IRPF
retention, IGIC, mixed/`suplido` lines and a 0%-IGIC image; reuse them and add new ones
(plus a matching `responses/*.json` wired into `InvoicePipelineTest`) when covering a new
case. `Test/` is `export-ignore`d, so fixtures never ship in the release ZIP.

For a non-interactive smoke test of the extraction→map→import path against the live
provider, run `Test/e2e-smoke.php` inside the container (it iterates the fixtures and
reports the created invoice, attachment and warnings):

```bash
docker compose cp Test/e2e-smoke.php facturascripts:/tmp/ \
  && docker compose exec -T facturascripts php84 /tmp/e2e-smoke.php
```

---

## Definition of Done

Before considering a change complete:

- [ ] `make format` runs without unexpected changes
- [ ] `make lint` reports **zero violations**
- [ ] `make test` reports **zero failures and zero errors**
- [ ] No warnings left in output
- [ ] Behavior is preserved (or intentionally changed and documented)
- [ ] CI is expected to pass (lint job + PHP matrix 8.1-8.5)
- [ ] Packaging exclusions in `Makefile` updated if new non-distributable files were added
- [ ] ADR created if an architectural decision was made (see `docs/adr/`)
