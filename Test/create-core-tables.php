<?php

/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 *
 * Crea todas las tablas del core antes de lanzar los tests.
 *
 * Desde NeoRazorX/facturascripts@3e84e9c1, delete() abre una transacción y
 * borra los registros relacionados (pagos de un recibo, imágenes de un
 * adjunto...). El core crea cada tabla la primera vez que se usa, pero no
 * dentro de una transacción, así que en una base de datos recién creada el
 * borrado lanza «cant create table pagosprov in transaction».
 *
 * Uso, desde la carpeta de FacturaScripts: php Plugins/AiScan/Test/create-core-tables.php
 */

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Template\ModelClass;

define('FS_FOLDER', getcwd());

require_once FS_FOLDER . '/vendor/autoload.php';
require_once FS_FOLDER . '/config.php';

$db = new DataBase();
$db->connect();
Kernel::init();

$created = 0;
foreach (glob(FS_FOLDER . '/Core/Model/*.php') as $file) {
    $class = 'FacturaScripts\\Core\\Model\\' . basename($file, '.php');
    if (!class_exists($class)) {
        continue;
    }

    $reflection = new ReflectionClass($class);
    if ($reflection->isInstantiable() && $reflection->isSubclassOf(ModelClass::class)) {
        new $class();
        $created++;
    }
}

echo 'Tablas del core comprobadas: ' . $created . PHP_EOL;
