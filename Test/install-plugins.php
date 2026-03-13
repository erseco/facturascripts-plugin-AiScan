<?php

/**
 * This file is part of PluginTemplate plugin for FacturaScripts.
 * Script to install plugins for testing
 */

// This file would normally be provided by FacturaScripts
// For local testing, we'll create a minimal version

if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    die("Please run composer install first\n");
}

require_once __DIR__ . '/../vendor/autoload.php';

// Read install-plugins.txt from Test/Plugins directory
$installFile = __DIR__ . '/Plugins/install-plugins.txt';
if (!file_exists($installFile)) {
    die("No install-plugins.txt found\n");
}

$plugins = trim(file_get_contents($installFile));
echo 'Plugins to install: ' . $plugins . "\n";

// In a real FacturaScripts installation, this would install the plugins
// For now, we just print them
echo "Plugin installation simulated (this would happen in FacturaScripts)\n";
