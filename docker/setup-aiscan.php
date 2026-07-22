<?php

/**
 * AiScan Docker auto-setup script.
 *
 * Configures AiScan plugin settings (API keys) from environment variables
 * and aligns the company/tax defaults with the playground blueprint so the
 * local stack can post purchase invoices with IGIC and accounting entries.
 *
 * Wizard completion and seed data loading are handled by the base container
 * (setup-facturascripts.php and seed-facturascripts.php via FS_SEED_FILE).
 *
 * Usage: php setup-aiscan.php
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$configFile = '/var/www/html/config.php';
if (!file_exists($configFile)) {
    echo "[AiScan] config.php not found. Skipping.\n";
    exit(0);
}

define('FS_FOLDER', '/var/www/html');
require_once FS_FOLDER . '/vendor/autoload.php';
require_once $configFile;

use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;

Kernel::init();
Plugins::deploy(true, true);

echo "[AiScan] Configuring AiScan plugin...\n";

// Enable the plugin if not already enabled
if (!in_array('AiScan', Plugins::enabled())) {
    if (Plugins::enable('AiScan')) {
        Plugins::deploy(true, true);
        echo "[AiScan] Plugin enabled.\n";
    } else {
        echo "[AiScan] WARNING: Could not enable plugin.\n";
    }
} else {
    echo "[AiScan] Plugin already enabled.\n";
}

// Ensure all AiScan model tables exist by instantiating them
// (FS creates tables on first model instantiation via DbUpdater)
$aiscanModels = [
    'AiScanImportBatch', 'AiScanImportDocument', 'AiScanImportLine',
    'AiScanLog', 'AiScanSupplierProduct',
];
foreach ($aiscanModels as $name) {
    $cls = '\\FacturaScripts\\Dinamic\\Model\\' . $name;
    if (class_exists($cls)) {
        new $cls();
    }
}
echo "[AiScan] Plugin tables ensured.\n";

// Align company + defaults for Canary Islands billing (IGIC / PGC).
configureBillingDefaults();

// Configure API keys from environment variables
configureApiKeys();

echo "[AiScan] Setup completed.\n";

/**
 * Ensure the instance can create and post invoices with IGIC and asientos.
 *
 * Reads optional overrides from the same install-oriented env vars used by
 * alpine-facturascripts, falling back to the Canarias demo used in blueprint.json.
 */
function configureBillingDefaults(): void
{
    $codpais = getenv('FS_CODPAIS') ?: 'ESP';
    $companyName = getenv('FS_COMPANY_NAME') ?: 'Pepita Gómez - Autónoma';
    $companyCif = getenv('FS_COMPANY_CIF') ?: '125478938W';
    $regimenIva = getenv('FS_COMPANY_REGIMENIVA') ?: 'General';
    $defaultTax = getenv('FS_DEFAULT_TAX') ?: 'IGIC7';
    $companyAddress = getenv('FS_COMPANY_ADDRESS') ?: 'Calle Castillo 15';
    $companyPostal = getenv('FS_COMPANY_POSTAL') ?: '38003';
    $companyCity = getenv('FS_COMPANY_CITY') ?: 'Santa Cruz de Tenerife';
    $companyProvince = getenv('FS_COMPANY_PROVINCE') ?: 'Santa Cruz de Tenerife';

    // Ensure country tax CSV rows (IVA + IGIC + IPSI) exist.
    $taxModel = new \FacturaScripts\Dinamic\Model\Impuesto();
    if ($taxModel->count() === 0) {
        echo "[AiScan] WARNING: no taxes found after setup.\n";
    }

    if (!$taxModel->loadFromCode($defaultTax)) {
        echo "[AiScan] WARNING: default tax {$defaultTax} not found; keeping current default.\n";
        $defaultTax = (string) Tools::settings('default', 'codimpuesto', 'IVA21');
    }

    $empresa = new \FacturaScripts\Dinamic\Model\Empresa();
    $empresaReady = $empresa->loadFromCode(1);
    if ($empresaReady) {
        $empresa->nombre = $companyName;
        $empresa->nombrecorto = Tools::textBreak($companyName, 32);
        $empresa->cifnif = $companyCif;
        $empresa->tipoidfiscal = getenv('FS_COMPANY_TIPOIDFISCAL') ?: 'NIF';
        $empresa->codpais = $codpais;
        $empresa->regimeniva = $regimenIva;
        $empresa->direccion = $companyAddress;
        $empresa->codpostal = $companyPostal;
        $empresa->ciudad = $companyCity;
        $empresa->provincia = $companyProvince;
        if ($empresa->save()) {
            echo "[AiScan] Company configured: {$empresa->nombre} ({$empresa->cifnif}).\n";
        } else {
            echo "[AiScan] WARNING: could not save company defaults.\n";
        }
    }

    $codalmacen = (string) Tools::settings('default', 'codalmacen', '');
    if ($codalmacen === '') {
        $almacen = new \FacturaScripts\Dinamic\Model\Almacen();
        foreach ($almacen->all([], [], 0, 1) as $first) {
            $codalmacen = (string) $first->codalmacen;
            $first->codpais = $codpais;
            $first->direccion = $companyAddress;
            $first->codpostal = $companyPostal;
            $first->ciudad = $companyCity;
            $first->provincia = $companyProvince;
            $first->save();
            break;
        }
    }

    Tools::settingsSet('default', 'codpais', $codpais);
    Tools::settingsSet('default', 'coddivisa', 'EUR');
    Tools::settingsSet('default', 'codimpuesto', $defaultTax);
    Tools::settingsSet('default', 'codpago', Tools::settings('default', 'codpago', 'CONT') ?: 'CONT');
    Tools::settingsSet('default', 'codserie', Tools::settings('default', 'codserie', 'A') ?: 'A');
    Tools::settingsSet('default', 'tipoidfiscal', 'NIF');
    Tools::settingsSet('default', 'regimeniva', $regimenIva);
    Tools::settingsSet('default', 'updatesupplierprices', true);
    Tools::settingsSet('default', 'ventasinstock', false);
    if ($codalmacen !== '') {
        Tools::settingsSet('default', 'codalmacen', $codalmacen);
    }
    if ($empresaReady) {
        Tools::settingsSet('default', 'idempresa', $empresa->idempresa);
    }
    Tools::settingsSave();
    echo "[AiScan] Default tax: {$defaultTax}; warehouse: " . ($codalmacen ?: '(none)') . ".\n";

    ensureAccountingPlan($codpais);
}

/**
 * Import the country default accounting plan when no accounts exist yet.
 * Required so posted invoices can generate asientos with IGIC subcuentas.
 */
function ensureAccountingPlan(string $codpais): void
{
    $cuenta = new \FacturaScripts\Dinamic\Model\Cuenta();
    if ($cuenta->count() > 0) {
        echo "[AiScan] Accounting plan already present ({$cuenta->count()} accounts).\n";
        return;
    }

    $planFile = FS_FOLDER . '/Dinamic/Data/Codpais/' . $codpais . '/defaultPlan.csv';
    if (!is_file($planFile)) {
        echo "[AiScan] WARNING: accounting plan file missing: {$planFile}\n";
        return;
    }

    $ejercicio = new \FacturaScripts\Dinamic\Model\Ejercicio();
    $ejercicios = $ejercicio->all([], [], 0, 1);
    if (empty($ejercicios)) {
        echo "[AiScan] WARNING: no exercise found; cannot import accounting plan.\n";
        return;
    }

    $importClass = '\\FacturaScripts\\Dinamic\\Lib\\Accounting\\AccountingPlanImport';
    if (!class_exists($importClass)) {
        echo "[AiScan] WARNING: AccountingPlanImport not available.\n";
        return;
    }

    (new $importClass())->importCSV($planFile, $ejercicios[0]->codejercicio);
    echo "[AiScan] Accounting plan imported for exercise {$ejercicios[0]->codejercicio}.\n";
}

function configureApiKeys(): void
{
    $envMapping = [
        'OPENAI_API_KEY' => 'openai_api_key',
        'GEMINI_API_KEY' => 'gemini_api_key',
        'MISTRAL_API_KEY' => 'mistral_api_key',
        'ANTHROPIC_API_KEY' => 'custom_api_key',
        'OPENROUTER_API_KEY' => 'custom_api_key',
    ];

    $envProviderDefaults = [
        'OPENAI_API_KEY' => ['provider' => 'openai'],
        'GEMINI_API_KEY' => ['provider' => 'gemini'],
        'MISTRAL_API_KEY' => ['provider' => 'mistral'],
        'ANTHROPIC_API_KEY' => [
            'provider' => 'openai-compatible',
            'custom_base_url' => 'https://api.anthropic.com/v1',
            'custom_model' => 'claude-3-haiku-20240307',
        ],
        'OPENROUTER_API_KEY' => [
            'provider' => 'openai-compatible',
            'custom_base_url' => 'https://openrouter.ai/api/v1',
            'custom_model' => 'google/gemini-2.5-flash-lite',
        ],
    ];

    // Ensure settings row exists
    $settings = new \FacturaScripts\Dinamic\Model\Settings();
    $settings->loadFromCode('AiScan');
    if (!$settings->exists()) {
        $settings->name = 'AiScan';
        $settings->enabled = '1';
        $settings->default_provider = 'openai';
        $settings->max_upload_size_mb = '10';
        $settings->allowed_extensions = 'pdf,jpg,jpeg,png,webp';
        $settings->auto_scan = '0';
        $settings->debug_mode = '0';
        $settings->request_timeout = '120';
        $settings->openai_model = 'gpt-5-nano';
        $settings->openai_base_url = 'https://api.openai.com/v1';
        $settings->gemini_model = 'gemini-2.5-flash-lite';
        $settings->mistral_model = 'mistral-small-latest';
        $settings->save();
        echo "[AiScan] Settings created.\n";
    }

    $updated = false;
    $selectedProvider = null;

    foreach ($envMapping as $envVar => $settingsKey) {
        $value = getenv($envVar);
        if (empty($value)) {
            continue;
        }

        $current = $settings->getProperty($settingsKey);
        if (empty($current)) {
            $settings->$settingsKey = $value;
            $updated = true;
            echo "[AiScan] Set {$settingsKey} from {$envVar}.\n";
        }

        if ($selectedProvider === null) {
            $config = $envProviderDefaults[$envVar] ?? [];
            $selectedProvider = $config['provider'] ?? null;
            if ($selectedProvider) {
                $settings->default_provider = $selectedProvider;
                $updated = true;
                echo "[AiScan] Default provider: {$selectedProvider}\n";
            }
            foreach (['custom_base_url', 'custom_model'] as $field) {
                if (!empty($config[$field])) {
                    $settings->$field = $config[$field];
                    $updated = true;
                }
            }
        }
    }

    if ($updated) {
        $settings->save();
        echo "[AiScan] Settings saved.\n";
    } else {
        echo "[AiScan] No API keys in environment.\n";
    }
}
