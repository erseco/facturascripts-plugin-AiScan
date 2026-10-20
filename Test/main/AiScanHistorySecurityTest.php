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

use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Lib\MultiRequestProtection;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\AiScan\Controller\EditAiScanImportBatch;
use FacturaScripts\Plugins\AiScan\Model\AiScanImportBatch;
use FacturaScripts\Plugins\AiScan\Model\AiScanImportDocument;
use FacturaScripts\Plugins\AiScan\Model\AiScanImportLine;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Issue #129: el historial guardaba HTML sin escapar y aceptaba escrituras por POST.
 */
final class AiScanHistorySecurityTest extends TestCase
{
    private const PAYLOAD = '<img src=x onerror="alert(1)">';

    private ?AiScanImportBatch $batch = null;
    private ?AiScanImportDocument $document = null;
    private ?AiScanImportLine $line = null;

    protected function tearDown(): void
    {
        $this->line?->delete();
        $this->document?->delete();
        $this->batch?->delete();
        Tools::log()->clear();
    }

    public function testHistoryModelsEscapeHtmlOnSave(): void
    {
        $this->createHistory();

        self::assertStringNotContainsString('<', $this->batch->provider);
        self::assertStringNotContainsString('<', $this->document->originalname);
        self::assertStringNotContainsString('<', $this->document->suppliername);
        self::assertStringNotContainsString('<', $this->document->numproveedor);
        self::assertStringNotContainsString('<', $this->document->errormessage);
        self::assertStringNotContainsString('<', $this->line->descripcion);
        self::assertStringNotContainsString('<', $this->line->referencia);
        self::assertSame(Tools::noHtml(self::PAYLOAD), $this->line->descripcion);
        self::assertSame(30, mb_strlen($this->line->referencia));
    }

    public function testHistoryPageRejectsWriteActions(): void
    {
        $this->createHistory();
        $batchCount = (new AiScanImportBatch())->count();
        $admin = new User();
        $admin->admin = true;
        $admin->nick = 'admin';

        $expected = [
            'edit' => 'not-allowed-modify',
            'insert' => 'not-allowed-modify',
            'delete' => 'not-allowed-delete',
        ];
        foreach ($expected as $name => $warning) {
            Tools::log()->clear();
            $page = new EditAiScanImportBatch('EditAiScanImportBatch');
            $page->request = new Request(['request' => [
                'action' => $name,
                'activetab' => 'EditAiScanImportBatch',
                'code' => (string) $this->batch->id,
                'provider' => 'tampered',
                'multireqtoken' => (new MultiRequestProtection())->newToken(),
            ]]);
            $page->permissions = new ControllerPermissions($admin, 'EditAiScanImportBatch');
            $page->active = 'EditAiScanImportBatch';
            $create = new ReflectionMethod($page, 'createViews');
            $create->setAccessible(true);
            $create->invoke($page);
            $action = new ReflectionMethod($page, 'execPreviousAction');
            $action->setAccessible(true);
            $action->invoke($page, $name);
            $messages = array_column(Tools::log()->read('', ['warning']), 'message');
            self::assertContains(Tools::lang()->trans($warning), $messages, $name);
        }

        $batch = new AiScanImportBatch();
        self::assertTrue($batch->load($this->batch->id));
        self::assertSame($this->batch->provider, $batch->provider);
        self::assertSame($batchCount, $batch->count());
    }

    private function createHistory(): void
    {
        $this->batch = new AiScanImportBatch();
        $this->batch->provider = self::PAYLOAD;
        self::assertTrue($this->batch->save());

        $this->document = new AiScanImportDocument();
        $this->document->idbatch = $this->batch->id;
        $this->document->originalname = self::PAYLOAD . '.pdf';
        $this->document->suppliername = self::PAYLOAD;
        $this->document->numproveedor = self::PAYLOAD;
        $this->document->errormessage = self::PAYLOAD;
        self::assertTrue($this->document->save());

        $this->line = new AiScanImportLine();
        $this->line->iddocument = $this->document->id;
        $this->line->descripcion = self::PAYLOAD;
        $this->line->referencia = self::PAYLOAD;
        self::assertTrue($this->line->save());
    }
}
