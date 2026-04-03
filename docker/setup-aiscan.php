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

// Ensure all AiScan plugin tables exist via raw SQL (FS DbUpdater
// silently fails on some table XML definitions with defaults)
$db = new \FacturaScripts\Core\Base\DataBase();
$db->connect();

$installSql = [
    'aiscan_import_batches' => 'CREATE TABLE IF NOT EXISTS aiscan_import_batches ('
        . ' id int(11) NOT NULL AUTO_INCREMENT,'
        . ' nick varchar(50) DEFAULT NULL,'
        . ' importmode varchar(20) NOT NULL DEFAULT "lines",'
        . ' provider varchar(50) DEFAULT NULL,'
        . ' totaldocuments int(11) NOT NULL DEFAULT 0,'
        . ' importedcount int(11) NOT NULL DEFAULT 0,'
        . ' discardedcount int(11) NOT NULL DEFAULT 0,'
        . ' failedcount int(11) NOT NULL DEFAULT 0,'
        . ' created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' PRIMARY KEY (id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
    'aiscan_import_documents' => 'CREATE TABLE IF NOT EXISTS aiscan_import_documents ('
        . ' id int(11) NOT NULL AUTO_INCREMENT,'
        . ' idbatch int(11) NOT NULL,'
        . ' originalname varchar(255) NOT NULL,'
        . ' codproveedor varchar(10) DEFAULT NULL,'
        . ' suppliername varchar(100) DEFAULT NULL,'
        . ' numproveedor varchar(50) DEFAULT NULL,'
        . ' fecha date DEFAULT NULL,'
        . ' coddivisa varchar(3) DEFAULT "EUR",'
        . ' neto double NOT NULL DEFAULT 0,'
        . ' totaliva double NOT NULL DEFAULT 0,'
        . ' total double NOT NULL DEFAULT 0,'
        . ' status varchar(20) NOT NULL DEFAULT "pending",'
        . ' idfactura int(11) DEFAULT NULL,'
        . ' codigofactura varchar(20) DEFAULT NULL,'
        . ' errormessage text DEFAULT NULL,'
        . ' created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' PRIMARY KEY (id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
    'aiscan_import_lines' => 'CREATE TABLE IF NOT EXISTS aiscan_import_lines ('
        . ' id int(11) NOT NULL AUTO_INCREMENT,'
        . ' iddocument int(11) NOT NULL,'
        . ' sortorder int(11) NOT NULL DEFAULT 0,'
        . ' descripcion varchar(255) NOT NULL,'
        . ' cantidad double NOT NULL DEFAULT 1,'
        . ' pvpunitario double NOT NULL DEFAULT 0,'
        . ' dtopor double NOT NULL DEFAULT 0,'
        . ' iva double NOT NULL DEFAULT 0,'
        . ' pvptotal double NOT NULL DEFAULT 0,'
        . ' referencia varchar(30) DEFAULT NULL,'
        . ' PRIMARY KEY (id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
    'aiscan_logs' => 'CREATE TABLE IF NOT EXISTS aiscan_logs ('
        . ' id int(11) NOT NULL AUTO_INCREMENT,'
        . ' idfactura int(11) DEFAULT NULL,'
        . ' filename varchar(255) NOT NULL,'
        . ' mime_type varchar(100) NOT NULL,'
        . ' provider varchar(50) NOT NULL DEFAULT "openai",'
        . ' model varchar(100) DEFAULT NULL,'
        . ' status varchar(20) NOT NULL DEFAULT "pending",'
        . ' raw_payload text DEFAULT NULL,'
        . ' error_message text DEFAULT NULL,'
        . ' confidence double NOT NULL DEFAULT 0,'
        . ' created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' PRIMARY KEY (id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
    'aiscan_supplier_products' => 'CREATE TABLE IF NOT EXISTS aiscan_supplier_products ('
        . ' id int(11) NOT NULL AUTO_INCREMENT,'
        . ' codproveedor varchar(10) NOT NULL,'
        . ' referencia varchar(30) NOT NULL,'
        . ' description varchar(255) DEFAULT NULL,'
        . ' created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' PRIMARY KEY (id),'
        . ' UNIQUE KEY aiscan_supplier_products_unique (codproveedor)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
];

foreach ($installSql as $table => $sql) {
    if (!$db->tableExists($table)) {
        $db->exec($sql);
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
