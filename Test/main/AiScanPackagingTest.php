<?php

/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AiScan\Init;
use FacturaScripts\Plugins\AiScan\Model\AiScanImportBatch;
use FacturaScripts\Plugins\AiScan\Model\AiScanImportDocument;
use FacturaScripts\Plugins\AiScan\Model\AiScanImportLine;
use PHPUnit\Framework\TestCase;

/**
 * Issue #136: traducciones que faltaban y claves foráneas del historial.
 */
final class AiScanPackagingTest extends TestCase
{
    public function testHistoryStatusesAndErrorsAreTranslated(): void
    {
        foreach (['es_ES', 'en_EN'] as $lang) {
            $file = FS_FOLDER . '/Plugins/AiScan/Translation/' . $lang . '.json';
            $keys = json_decode((string) file_get_contents($file), true);
            foreach (['discarded', 'imported', 'permission-denied'] as $key) {
                self::assertArrayHasKey($key, $keys, $lang . ': ' . $key);
            }
        }
    }

    public function testDeletingABatchDeletesItsDocumentsAndLines(): void
    {
        $batch = new AiScanImportBatch();
        self::assertTrue($batch->save());
        $document = new AiScanImportDocument();
        $document->idbatch = $batch->id;
        $document->originalname = 'cascade.pdf';
        self::assertTrue($document->save());
        $line = new AiScanImportLine();
        $line->iddocument = $document->id;
        $line->descripcion = 'Cascade';
        self::assertTrue($line->save());

        self::assertTrue($batch->delete());

        self::assertFalse((new AiScanImportDocument())->load($document->id));
        self::assertFalse((new AiScanImportLine())->load($line->id));
    }

    public function testHistoryRowsNeedAnExistingParent(): void
    {
        $document = new AiScanImportDocument();
        $document->idbatch = 999999999;
        $document->originalname = 'orphan.pdf';

        self::assertFalse($document->save());
        Tools::log()->clear();
    }

    public function testUpdateRemovesOrphansBeforeTheConstraintsAreAdded(): void
    {
        $db = new DataBase();
        if (strtolower(Tools::config('db_type', 'mysql')) !== 'mysql') {
            self::markTestSkipped('Solo MySQL/MariaDB permite desactivar las claves foráneas por sesión.');
        }

        self::assertTrue($db->exec('SET FOREIGN_KEY_CHECKS = 0;'));
        try {
            self::assertTrue($db->exec('INSERT INTO aiscan_import_documents'
                . ' (idbatch, originalname, coddivisa, neto, totaliva, total, status)'
                . " VALUES (999999998, 'orphan.pdf', 'EUR', 0, 0, 0, 'pending');"));
        } finally {
            $db->exec('SET FOREIGN_KEY_CHECKS = 1;');
        }

        Init::removeHistoryOrphans($db);

        $orphans = $db->select('SELECT COUNT(*) AS n FROM aiscan_import_documents WHERE idbatch = 999999998;');
        self::assertSame(0, (int) $orphans[0]['n']);
    }
}
