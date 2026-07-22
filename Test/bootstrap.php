<?php

/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 *
 * PHPUnit bootstrap for plugin tests.
 *
 * Prefer the official FacturaScripts Test/test-plugins.php when present
 * (Kernel + Plugins init). This file is a fallback used by `make test`
 * when that bootstrap is not available.
 */

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Plugins;

// Official FS plugin bootstrap (preferred).
$official = __DIR__ . '/test-plugins.php';
if (is_file($official) && realpath($official) !== realpath(__FILE__)) {
    require_once $official;
    return;
}

// Define FacturaScripts folder
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', dirname(__DIR__));
}

// Load composer autoloader
$loader = require FS_FOLDER . '/vendor/autoload.php';

// Load FacturaScripts configuration
if (file_exists(FS_FOLDER . '/config.php')) {
    require_once FS_FOLDER . '/config.php';
}

// Initialize minimal FacturaScripts environment for testing
if (!defined('FS_LANG')) {
    define('FS_LANG', 'es_ES');
}

if (!defined('FS_TIMEZONE')) {
    define('FS_TIMEZONE', 'Europe/Madrid');
}

// Constants normally defined by Kernel::init() from DB settings.
if (!defined('FS_NF0')) {
    define('FS_NF0', 2);
}

if (!defined('FS_NF1')) {
    define('FS_NF1', ',');
}

if (!defined('FS_NF2')) {
    define('FS_NF2', ' ');
}

if (!defined('FS_CODPAIS')) {
    define('FS_CODPAIS', 'ESP');
}

if (!defined('FS_CURRENCY_POS')) {
    define('FS_CURRENCY_POS', 'right');
}

if (!defined('FS_ITEM_LIMIT')) {
    define('FS_ITEM_LIMIT', 50);
}

// Ensure PSR-4 roots are registered (Dinamic is required for Proveedor, etc.)
$loader->addPsr4('FacturaScripts\\Core\\', FS_FOLDER . '/Core/');
$loader->addPsr4('FacturaScripts\\Dinamic\\', FS_FOLDER . '/Dinamic/');
$loader->addPsr4('FacturaScripts\\Plugins\\', FS_FOLDER . '/Plugins/');
$loader->addPsr4('FacturaScripts\\Plugins\\AiScan\\', FS_FOLDER . '/Plugins/AiScan/');
$loader->addPsr4('FacturaScripts\\Test\\', FS_FOLDER . '/Test/');

// Boot Kernel + plugins when possible (same spirit as Test/test-plugins.php).
try {
    if (class_exists(DataBase::class)) {
        $db = new DataBase();
        if (method_exists($db, 'connect')) {
            $db->connect();
        }
    }
    if (class_exists(Cache::class) && method_exists(Cache::class, 'clear')) {
        Cache::clear();
    }
    if (class_exists(Kernel::class) && method_exists(Kernel::class, 'init')) {
        Kernel::init();
    }
    if (class_exists(Plugins::class) && method_exists(Plugins::class, 'init')) {
        Plugins::init();
    }
} catch (Throwable $e) {
    // Keep going: pure unit tests must still run without a full FS boot.
    fwrite(STDERR, '[AiScan test bootstrap] partial init: ' . $e->getMessage() . PHP_EOL);
}
