<?php

/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\AiScan\Model\AiScanImportBatch;
use FacturaScripts\Plugins\AiScan\Model\AiScanImportDocument;
use PHPUnit\Framework\TestCase;

/**
 * Issue #93: el enlace al historial apuntaba a ListAiScanHistoryAiScanImportBatch
 * (404), porque ModelClass::url() concatena el prefijo con el nombre del modelo.
 */
final class AiScanHistoryUrlTest extends TestCase
{
    public function testBatchListUrlPointsToTheHistoryController(): void
    {
        $batch = new AiScanImportBatch();

        $this->assertSame('ListAiScanHistory', $batch->url('list'));
        $this->assertSame('ListAiScanHistory', $batch->url());
    }

    public function testDocumentListUrlPointsToTheHistoryController(): void
    {
        $document = new AiScanImportDocument();

        $this->assertSame('ListAiScanHistory', $document->url('list'));
        $this->assertSame('ListAiScanHistory', $document->url());
    }

    public function testEditUrlKeepsPointingToTheBatchController(): void
    {
        $batch = new AiScanImportBatch();
        $batch->id = 7;

        $this->assertSame('EditAiScanImportBatch?code=7', $batch->url('edit'));
        $this->assertSame('EditAiScanImportBatch?code=7', $batch->url());
    }
}
