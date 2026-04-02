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
| `.github/workflows/ci.yml` | CI pipeline (Docker lint+test, then PHP matrix 8.1-8.4) |
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

## Definition of Done

Before considering a change complete:

- [ ] `make format` runs without unexpected changes
- [ ] `make lint` reports **zero violations**
- [ ] `make test` reports **zero failures and zero errors**
- [ ] No warnings left in output
- [ ] Behavior is preserved (or intentionally changed and documented)
- [ ] CI is expected to pass (lint job + PHP matrix 8.1-8.4)
- [ ] Packaging exclusions in `Makefile` updated if new non-distributable files were added
- [ ] ADR created if an architectural decision was made (see `docs/adr/`)
