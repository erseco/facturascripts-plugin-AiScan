<?php

/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 *
 * PHPUnit bootstrap file for testing
 */

// Define FacturaScripts folder
define('FS_FOLDER', __DIR__ . '/..');

// Load composer autoloader
require_once FS_FOLDER . '/vendor/autoload.php';

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
// Provide defaults so tests work even when phpunit-plugins.xml uses this
// bootstrap instead of the FacturaScripts Test/test-plugins.php bootstrap.
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

// Register plugin namespaces with the autoloader
$loader = require FS_FOLDER . '/vendor/autoload.php';

// Register FacturaScripts Core
$loader->addPsr4('FacturaScripts\\Core\\', FS_FOLDER . '/Core');

// Register AiScan
$loader->addPsr4('FacturaScripts\\Plugins\\AiScan\\', FS_FOLDER . '/Plugins/AiScan');

// If your plugin depends on other plugins, register them here as well
// Example: $loader->addPsr4('FacturaScripts\\Plugins\\OtherPlugin\\', FS_FOLDER . '/Plugins/OtherPlugin');
