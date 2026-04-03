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

use FacturaScripts\Plugins\AiScan\Extension\Controller\EditFacturaProveedor;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EditFacturaProveedorExtensionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $base = dirname(__DIR__, 2) . '/Plugins/AiScan';
        require_once $base
            . '/Extension/Controller/EditFacturaProveedor.php';
    }

    public function testCreateViewsAddsLinkButton(): void
    {
        $extension = new EditFacturaProveedor();
        $controller = new class () {
            public array $buttons = [];

            public function getMainViewName(): string
            {
                return 'MainView';
            }

            public function addButton(string $viewName, array $btnArray): void
            {
                $this->buttons[] = [$viewName, $btnArray];
            }
        };

        $closure = $extension->createViews();
        $closure->call($controller);

        $this->assertCount(1, $controller->buttons);
        $this->assertSame('MainView', $controller->buttons[0][0]);
        $this->assertSame('js', $controller->buttons[0][1]['type']);
        $this->assertStringContainsString('AiScanInvoice', $controller->buttons[0][1]['action']);
        $this->assertSame('aiscan-page-title', $controller->buttons[0][1]['label']);
    }
}
