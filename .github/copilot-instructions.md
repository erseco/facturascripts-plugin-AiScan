# GitHub Copilot Instructions

Canonical instructions are in [`/AGENTS.md`](/AGENTS.md). Read that file first.

## Critical rules (repeated here for reliability)

- This is a **FacturaScripts 2025+ plugin** written in PHP 8.1+. Keep that compatibility.
- Follow **PSR-12**; max line length **120 characters**; short array syntax; single quotes.
- Make **minimal, focused diffs** — no unrelated refactors.
- **Preserve existing behavior** unless the task explicitly requires a change.
- Update tests when changing covered behavior.

## Validation — run in this order

```bash
make format   # PHP CS Fixer auto-fix (must run clean)
make lint     # PHPCS check (zero violations required)
make test     # PHPUnit (zero failures required)
```

- Do **not** leave PHPCS violations.
- Do **not** leave failing or skipped tests.
- CI runs lint + tests on PHP 8.1, 8.2, 8.3, and 8.4 — changes must pass all.
