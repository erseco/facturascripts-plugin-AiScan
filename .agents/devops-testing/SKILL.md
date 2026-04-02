---
name: devops-testing
description: DevOps, CI/CD and testing for AiScan. Docker workflow, Makefile commands, PHPUnit in container, JS tests via Node --test, GitHub Actions CI matrix PHP 8.1-8.4, test naming conventions.
---

# DevOps & Testing — AiScan Infrastructure

## Docker Environment

### Essential Commands
```bash
make upd          # Start containers (detached) — auto-called by lint/format/test
make down         # Stop containers
make shell        # Open shell inside facturascripts container
make logs         # Tail container logs
make fresh        # Clean volumes + restart (fresh database)
make rebuild      # Rebuild FacturaScripts dynamic classes
```

### Container Details
- Image: FacturaScripts with PHP 8.4 (`php84` binary, NOT `php`)
- Plugin mounted at `/var/www/html/Plugins/AiScan/`
- MySQL available inside container
- Web UI: `http://localhost:8080` (admin/admin)

## Validation Workflow (Sacred — Never Skip)

```bash
make format   # Step 1: PHP CS Fixer auto-fix
make lint     # Step 2: PHPCS check (zero violations required)
make test     # Step 3: PHPUnit + JS tests (zero failures required)
```

**Order matters.** Format first (auto-fixes), then lint (catches remaining issues), then test.

## PHP Testing

### Structure
```
Test/
  bootstrap.php          # FacturaScripts environment setup
  install-plugins.php    # Plugin dependency installer
  main/                  # PHPUnit test classes
    AiScanInvoiceControllerTest.php
    AiScanSettingsTest.php
    EditFacturaProveedorExtensionTest.php
    ExtractionServiceTest.php
    InvoiceMapperTest.php
    SchemaValidatorTest.php
    SupplierMatcherTest.php
```

### How `make test` Works
1. Installs PHPUnit in container if absent
2. Copies `Test/main/*` to container's `Test/Plugins/` directory
3. Copies `Test/bootstrap.php` and `Test/install-plugins.php`
4. Runs `install-plugins.php` to set up dependencies
5. Generates `phpunit-plugins.xml` config
6. Executes: `php84 vendor/bin/phpunit -c phpunit-plugins.xml`

### Test Naming
- File: `{ClassName}Test.php` (e.g., `SchemaValidatorTest.php`)
- Class: `{ClassName}Test extends TestCase`
- Methods: `test{Behavior}()` (e.g., `testValidateReturnsErrorsForMissingFields`)

### Rules
- **Never leave failing tests** — fix the issue, don't skip the test
- **Never remove tests** to make the suite pass
- **Add tests** when: adding new class, changing public method, fixing a bug
- **Update tests** when: changing behavior of covered code paths
- Tests must work inside the FacturaScripts container environment (not standalone)

## JavaScript Testing

### Running
```bash
node --test Test/js/*.test.js
```

### Structure
```
Test/js/
  aiscan-flow.test.js      # Tests for UMD library (pure functions)
  aiscan-fallback.test.js   # Tests for IIFE fallback logic
```

### Rules
- Uses Node.js built-in `test` and `assert` modules — no external test framework
- Tests run in Node.js (no browser, no jsdom)
- Test pure logic only — DOM interaction tested manually or via E2E

## CI Pipeline (.github/workflows/ci.yml)

### Job 1: Docker Environment Test
1. Start Docker containers
2. Copy plugin into container
3. Run `make lint`
4. Run `make test`

### Job 2: PHP Matrix Test
Matrix: **PHP 8.1, 8.2, 8.3, 8.4**

1. Clone FacturaScripts repository
2. Copy plugin to `Plugins/AiScan/`
3. Set up MySQL service
4. Install Composer dependencies
5. Run JS tests (`node --test`)
6. Generate PHPUnit config
7. Run PHPUnit tests

**All PHP versions must pass.** Do not use PHP 8.2+ features unless `facturascripts.ini` min PHP is updated.

## Packaging

```bash
make package VERSION=1.2.3
```

- Creates `dist/AiScan-1.2.3.zip`
- Excludes: tests, dev configs, docs, agent files, Docker files, Git files
- Updates version in `facturascripts.ini` during build, reverts after
- If adding new non-distributable files/directories → add exclusion to `Makefile`

## Reference Files
- `Makefile` — All development commands
- `.github/workflows/ci.yml` — CI pipeline
- `docker-compose.yml` — Docker service definitions
- `Test/` — All test files
- `phpcs.xml` — PHPCS configuration
- `.php-cs-fixer.php` — PHP CS Fixer configuration
