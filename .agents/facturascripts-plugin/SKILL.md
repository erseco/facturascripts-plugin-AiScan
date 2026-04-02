---
name: facturascripts-plugin
description: FacturaScripts 2025+ plugin architecture. Controller patterns, Extension closures, XMLView/Table XML, Model conventions, Init.php lifecycle, Settings API, AssetManager, Dinamic namespace.
---

# FacturaScripts Plugin Expert — AiScan Architecture

## Plugin Lifecycle (Init.php)

```php
class Init extends InitClass {
    public function init(): void    // Called on every request — load extensions here
    public function update(): void  // Called on install/update — initialize settings, migrations
    public function uninstall(): void // Called on plugin removal — cleanup
}
```

- `init()`: Register extensions via `$this->loadExtension(new Extension\Controller\Foo())`
- `update()`: Set default settings, run one-time migrations
- Extensions must be loaded in `init()`, NOT `update()`

## Controllers

### PanelController (settings, detail views)
```php
class AiScanConfig extends PanelController {
    protected function createViews(): void {
        $this->addHtmlView('config', 'AiScanConfig', 'Settings', 'config');
    }
    public function getPageData(): array {
        return ['menu' => 'admin', 'title' => 'ai-scan-config', 'icon' => 'fas fa-brain'];
    }
}
```

### Custom API Controller
`AiScanInvoice` extends `Controller` directly for REST-style JSON endpoints:
- Override `publicCore(&$response)` or handle in `privateCore()`
- Return JSON via `$this->response->setContent(json_encode($data))`
- Check permissions: `$this->permissions->allowUpdate`

## Extension Pattern (Closure-Return)

Extensions modify existing FacturaScripts controllers without editing core files:

```php
// Extension/Controller/EditFacturaProveedor.php
class EditFacturaProveedor
{
    // Method name = hook point in the target controller
    public function createViews(): Closure
    {
        return function () {
            // $this = the target controller instance
            AssetManager::addCss(FS_ROUTE . '/Plugins/AiScan/Assets/CSS/aiscan.css');
            AssetManager::addJs(FS_ROUTE . '/Plugins/AiScan/Assets/JS/aiscan.js');
        };
    }
}
```

**Rules:**
- Method names match the target controller method they hook into
- The closure receives `$this` as the target controller (not the extension)
- Use `FS_ROUTE` prefix for asset paths
- Extension class goes in `Extension/Controller/` mirroring the target controller name

## Settings API

```php
// Read setting
$value = Tools::settings('AiScan', 'key_name', 'default_value');

// Write setting (in update() or controller action)
$settings = new Settings();
$settings->name = 'AiScan';
$settings->properties = ['key' => 'value', ...];
$settings->save();
```

- Settings namespace: `'AiScan'`
- `AiScanSettings` helper in `Lib/AiScanSettings.php` wraps `Tools::settings()` with typed defaults

## Asset Injection

```php
AssetManager::addCss(FS_ROUTE . '/Plugins/AiScan/Assets/CSS/aiscan.css');
AssetManager::addJs(FS_ROUTE . '/Plugins/AiScan/Assets/JS/aiscan-flow.js');
AssetManager::addJs(FS_ROUTE . '/Plugins/AiScan/Assets/JS/aiscan.js');
```

- Always use `FS_ROUTE` prefix — handles subfolder installations
- Load CSS before JS
- Load `aiscan-flow.js` before `aiscan.js` (dependency order)

## Models

```php
class AiScanLog extends ModelClass {
    public function primaryColumn(): string { return 'id'; }
    public static function tableName(): string { return 'aiscan_logs'; }
    public function primaryDescriptionColumn(): string { return 'filename'; }
}
```

- Inherit from `ModelClass` for CRUD operations
- Table schema defined in `Table/aiscan_logs.xml`
- Use `ModelClass` methods: `loadFromCode()`, `save()`, `delete()`, `all()`, `count()`

### Accessing Core Models (Dinamic Namespace)

```php
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\LineaFacturaProveedor;
```

- **Always** use `Dinamic` namespace for FS core models — enables other plugins to extend them
- Never use `FacturaScripts\Core\Model\...` directly

## XMLView Definitions

`XMLView/AiScanConfig.xml` defines the settings panel UI:
```xml
<view>
  <columns>
    <group name="general" title="general">
      <column name="enabled" numcolumns="4">
        <widget type="checkbox" fieldname="enabled" />
      </column>
    </group>
  </columns>
</view>
```

## Table Definitions

`Table/aiscan_logs.xml` defines database schema:
```xml
<table>
  <column><name>id</name><type>serial</type></column>
  <column><name>filename</name><type>character varying(255)</type></column>
  ...
  <constraint><name>aiscan_logs_pkey</name><type>PRIMARY KEY</type><columns>id</columns></constraint>
</table>
```

## Translation

- Strings in `Translation/es_ES.json` and `Translation/en_EN.json`
- Use `Tools::lang()->trans('key')` in PHP or `i18n.trans('key')` in Twig
- Keys are lowercase kebab-case: `ai-scan-config`, `scan-invoice`

## Reference Files
- `Init.php` — Plugin entry point
- `Controller/` — Main controllers
- `Extension/Controller/` — Controller extensions
- `Model/AiScanLog.php` — Audit log model
- `XMLView/AiScanConfig.xml` — Settings UI
- `Table/aiscan_logs.xml` — Database schema
- `Lib/AiScanSettings.php` — Settings helper
