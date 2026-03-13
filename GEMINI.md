# GEMINI.md

Repository-wide coding instructions are defined in [`/AGENTS.md`](/AGENTS.md).
Read that file for the full context before making any changes.

## Quick reference

- FacturaScripts 2025+ plugin, PHP 8.1+, PSR-12, 120-char line limit
- Minimal diffs — no unrelated refactors; preserve behavior unless explicitly asked to change it
- Validation order:
  1. `make format` — PHP CS Fixer (auto-fix style)
  2. `make lint` — PHPCS (zero violations required)
  3. `make test` — PHPUnit (zero failures required)
- Update tests when changing covered paths
- CI must be expected to pass on PHP 8.1–8.4
