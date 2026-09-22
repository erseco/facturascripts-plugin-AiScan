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

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AiScan\Model\AiScanLog;
use PHPUnit\Framework\TestCase;

final class AiScanLogTest extends TestCase
{
    public function testMissingFilenameProducesTranslatedWarning(): void
    {
        $model = new AiScanLog();
        $model->filename = '';
        Tools::log()->clear();

        $this->assertFalse($model->test());
        $warnings = Tools::log()->read('', ['warning']);
        $this->assertCount(1, $warnings);
        $this->assertSame(
            Tools::lang()->trans('aiscan-filename-required'),
            $warnings[0]['message']
        );
        Tools::log()->clear();
    }

    public function testValidFilenamePassesValidation(): void
    {
        $model = new AiScanLog();
        $model->filename = 'invoice.pdf';
        $model->mime_type = 'application/pdf';
        $model->provider = 'mock';

        $this->assertTrue($model->test());
        $this->assertSame('pending', $model->status);
        $this->assertSame('id', $model::primaryColumn());
        $this->assertSame('aiscan_logs', $model::tableName());
    }
}
