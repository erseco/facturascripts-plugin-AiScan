# AGENTS.md – Canonical Agent Instructions

This is the authoritative instruction file for all coding agents working in this repository.
All other agent files (`CLAUDE.md`, `GEMINI.md`, `.github/copilot-instructions.md`) refer here.

---

## Project Overview

**AiScan** is a FacturaScripts plugin that scans supplier invoices using AI (OpenAI, Google Gemini,
Mistral, or any OpenAI-compatible endpoint) and maps the extracted data into a purchase invoice.

- Plugin name: `AiScan`
- Compatibility: **FacturaScripts 2025+**, **PHP 8.1+**
- PSR-12 coding standard (enforced by PHPCS and PHP CS Fixer)
- Tests live in `Test/main/` and are copied into a FacturaScripts checkout to run

---

## Before Changing Code

Inspect these files before making assumptions:

| File | Purpose |
|---|---|
| `facturascripts.ini` | Plugin name, version, min FacturaScripts version, min PHP |
| `phpcs.xml` | PHPCS ruleset (PSR-12 + extras, 120-char line limit) |
| `.php-cs-fixer.php` | PHP CS Fixer config (PSR-12, short arrays, single quotes…) |
| `Makefile` | All development commands |
| `.github/workflows/ci.yml` | CI pipeline (Docker lint+test, then PHP matrix 8.1–8.4) |
| `Test/main/` | Unit tests |
| `Lib/` | Core business logic (matchers, mappers, services) |

---

## Coding Rules

- Follow **PSR-12**; max line length **120 characters**
- Use **short array syntax** (`[]` not `array()`)
- Use **single quotes** for strings unless interpolation is needed
- No unused imports; imports ordered alphabetically
- Forbidden function aliases: `sizeof` → `count`, `delete` → `unset`, `print` → `echo`
- Preserve FacturaScripts 2025 / PHP 8.1 compatibility — do not use PHP 8.2+ features unless the
  minimum PHP version in `facturascripts.ini` is updated
- Keep changes **minimal and focused** — avoid unrelated refactors
- **Preserve existing behavior** unless the task explicitly requires a behavior change
- Update tests when changing behavior or covered code paths
- Keep `README.md` / `QUICKSTART.md` in sync when behavior visible to users changes

---

## Validation Workflow

Run these commands in order after making changes:

```bash
make format   # PHP CS Fixer – auto-fixes style issues
make lint     # PHPCS – reports remaining violations (must be clean)
make test     # PHPUnit – all tests must pass
```

All three require Docker (`make upd` starts the container automatically).

- `make format` uses `friendsofphp/php-cs-fixer` with `.php-cs-fixer.php`
- `make lint` uses `squizlabs/php_codesniffer` with `phpcs.xml`
- `make test` copies `Test/main/*` into the container's `Test/Plugins/` and runs PHPUnit

`phpcbf` (PHPCS auto-fixer) is available inside the container but **`make format` is the canonical
formatting step** — use `make format` first, then `make lint` to verify.

---

## Testing Rules

- Tests live in `Test/main/`
- `make test` installs PHPUnit inside the container if absent, copies the test files, then runs them
- Do **not** leave failing tests
- Do **not** remove or skip tests to make the suite pass — fix the underlying issue
- When adding a new class or changing a public method, add or update the corresponding test

---

## Definition of Done

Before considering a change complete:

- [ ] `make format` runs without unexpected changes
- [ ] `make lint` reports **zero violations**
- [ ] `make test` reports **zero failures and zero errors**
- [ ] No warnings left in output
- [ ] Behavior is preserved (or intentionally changed and documented)
- [ ] CI is expected to pass (lint job + PHP matrix 8.1–8.4)
- [ ] Packaging exclusions in `Makefile` updated if new non-distributable files were added
