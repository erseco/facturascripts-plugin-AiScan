<?php

/**
 * AiScan Docker auto-setup script.
 *
 * Configures AiScan plugin settings (API keys) from environment variables.
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

// Configure API keys from environment variables
configureApiKeys();

echo "[AiScan] Setup completed.\n";

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
