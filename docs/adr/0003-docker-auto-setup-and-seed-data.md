# ADR-0003: Docker Auto-Setup and Seed Data

## Status

Accepted

## Date

2026-04-03

## Context

Testing the AiScan plugin required manual steps after `docker compose up`:
1. Complete the FacturaScripts installation Wizard in the browser.
2. Enable the AiScan plugin via AdminPlugins.
3. Configure AI provider API keys in AiScan settings.
4. Manually create suppliers and products for testing.

This made the development feedback loop slow and error-prone. The alpine-omeka-s base container already solved similar problems with `install_cli.php` and `import_from_csv()`.

## Decision

Split the auto-setup into two layers:

### Base container (alpine-facturascripts)

Two new CLI scripts were contributed to the base container (PR #19):

1. **`setup-facturascripts.php`**: Completes the FacturaScripts Wizard programmatically — initializes core models, sets country defaults, configures company, creates all database tables, loads accounting plan, sets admin homepage to Dashboard. Triggered by `FS_INITIAL_USER` env var.

2. **`seed-facturascripts.php`**: Generic seed data loader that reads a JSON file and creates records using FacturaScripts Dinamic models. Supports model name keys (`Proveedor`, `Producto`) and convenience aliases (`suppliers`, `products`). Triggered by `FS_SEED_FILE` env var. Duplicate detection via `_unique` field.

Also fixed the cron service restart loop (changed `exit 0` to `exec sleep infinity` when `RUN_CRON_TASKS=false`).

### Plugin layer (AiScan)

A slim `docker/setup-aiscan.php` (~120 lines) that only handles:
- Enabling the AiScan plugin via `Plugins::enable()`.
- Creating AiScan settings with defaults.
- Configuring AI provider API keys from environment variables (`OPENAI_API_KEY`, `GEMINI_API_KEY`, `MISTRAL_API_KEY`, `ANTHROPIC_API_KEY`, `DEEPSEEK_API_KEY`, `OPENROUTER_API_KEY`).
- Auto-selecting the default provider based on the first available key.

### docker-compose.yml

```yaml
environment:
  FS_COMPANY_NAME: "Empresa Demo AiScan"
  FS_COMPANY_CIF: "B12345678"
  FS_SEED_FILE: /var/www/html/Plugins/AiScan/blueprint.json
  OPENAI_API_KEY: "${OPENAI_API_KEY:-}"
  POST_CONFIGURE_COMMANDS: >-
    php84 /var/www/html/Plugins/AiScan/docker/setup-aiscan.php
```

### blueprint.json

Extended with an `install` section (for the playground) and a `seed` section containing 5 Spanish suppliers and 10 products (services, materials, hardware) for realistic testing.

## Consequences

### Positive

- `make up` gives a fully configured FacturaScripts with AiScan enabled, API keys set, and sample data ready. Zero manual steps.
- API keys are read from host environment variables — never committed to the repository.
- The base container features (`setup-facturascripts.php`, `seed-facturascripts.php`) are reusable across all FacturaScripts plugins, not just AiScan.
- Seed data is idempotent — records are skipped if they already exist.
- The blueprint.json serves double duty: seed data for Docker dev + configuration for the FacturaScripts Playground.

### Negative

- The plugin's Docker setup depends on alpine-facturascripts:main having the new scripts. Older base images fall back to POST_CONFIGURE_COMMANDS only.

### Neutral

- The `FacturaScripts\Core\Where` constructor does not work as `(column, operator, value)`. The `Where::eq()` static method must be used instead. This was discovered during seed script development.
- FacturaScripts stores plugin enabled state in the filesystem, not the `plugins` database table. `Plugins::enable()` is the only reliable way to activate a plugin.
