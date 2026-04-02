---
name: php-expert
description: PHP 8.1+ coding standards for this FacturaScripts plugin. PSR-12, 120-char lines, phpcs.xml rules, .php-cs-fixer.php config, forbidden aliases, namespace conventions, LGPL-3.0 headers.
---

# PHP Expert — AiScan Coding Standards

## Style Enforcement

Two tools enforce style — run both via `make format` then `make lint`:

| Tool | Config file | Role |
|---|---|---|
| PHP CS Fixer | `.php-cs-fixer.php` | Auto-fixes style (canonical formatter) |
| PHPCS | `phpcs.xml` | Reports remaining violations (must be zero) |

## Rules

### Formatting
- **PSR-12** strict — max line length **120 characters**
- **Short array syntax**: `[]` not `array()`
- **Single quotes** for strings unless interpolation is needed
- **No trailing whitespace**, single blank line at EOF
- Braces on own line for classes/methods, same line for control structures

### Imports
- No unused imports
- Alphabetically ordered
- One `use` per line, no group `use` statements

### Forbidden Aliases
These function aliases trigger PHPCS violations:

| Forbidden | Use instead |
|---|---|
| `sizeof()` | `count()` |
| `delete()` | `unset()` |
| `print` | `echo` |

### Namespace Convention
```php
namespace FacturaScripts\Plugins\AiScan;           // Root classes (Init.php)
namespace FacturaScripts\Plugins\AiScan\Controller; // Controllers
namespace FacturaScripts\Plugins\AiScan\Lib;        // Services
namespace FacturaScripts\Plugins\AiScan\Lib\Provider; // AI providers
namespace FacturaScripts\Plugins\AiScan\Model;      // Models
```

### Type Safety
- Type hints on all method parameters and return types
- `?Type` for nullable parameters/returns (not `Type|null` — PHP 8.1 compat)
- Use `array` type hint (not generic `mixed`) when array structure is known
- Document complex array shapes in docblock `@param` when type hint is insufficient

### PHP Version Compatibility
- **Minimum: PHP 8.1** — defined in `facturascripts.ini`
- Do NOT use PHP 8.2+ features (readonly classes, DNF types, `true`/`false`/`null` standalone types)
- Do NOT use PHP 8.3+ features (`#[Override]`, typed class constants, `json_validate()`)
- If minimum PHP must change, update `facturascripts.ini` first and document in ADR

### Error Handling
- No `@` error suppression operator
- Use explicit `try/catch` for expected failures (API calls, file operations)
- Let unexpected errors propagate — FacturaScripts handles uncaught exceptions
- Log errors via `Tools::log()` — never `error_log()` directly

### License Header
All PHP files must start with the LGPL-3.0 license block matching existing files in the project.

## Reference Files
- `phpcs.xml` — PHPCS ruleset with PSR-12 + project extras
- `.php-cs-fixer.php` — PHP CS Fixer config (PSR-12, short arrays, single quotes)
- `facturascripts.ini` — min PHP version declaration
