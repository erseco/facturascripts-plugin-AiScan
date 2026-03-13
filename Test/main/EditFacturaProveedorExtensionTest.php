<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Ernesto Serrano <ernesto@erseco.es>
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

require_once dirname(__DIR__, 2) . '/Extension/Controller/EditFacturaProveedor.php';

use FacturaScripts\Plugins\AiScan\Extension\Controller\EditFacturaProveedor;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class EditFacturaProveedorExtensionTest extends TestCase
{
    public function testCreateViewsUsesMainViewNameForButton(): void
    {
        $this->defineExtensionStubs();

        $extension = new EditFacturaProveedor();
        $controller = new class {
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
        $this->assertSame('aiscan', $controller->buttons[0][1]['action']);
        $this->assertArrayNotHasKey('row', $controller->buttons[0][1]);
        $this->assertSame('modal', $controller->buttons[0][1]['type']);
        $this->assertSame('modalaiscan', $controller->buttons[0][1]['target']);
    }

    public function testLoadDataOnlyStoresInvoiceIdOnMainView(): void
    {
        $extension = new EditFacturaProveedor();
        $controller = new class {
            public array $settings = [];

            public function getMainViewName(): string
            {
                return 'MainView';
            }

            public function setSettings(string $viewName, string $property, $value): void
            {
                $this->settings[] = [$viewName, $property, $value];
            }
        };

        $view = new class {
            public object $model;

            public function __construct()
            {
                $this->model = new class {
                    public function primaryColumnValue(): int
                    {
                        return 42;
                    }
                };
            }
        };

        $closure = $extension->loadData();
        $closure->call($controller, 'OtherView', $view);
        $this->assertSame([], $controller->settings);

        $closure->call($controller, 'MainView', $view);
        $this->assertSame([['MainView', 'aiscan_invoice_id', 42]], $controller->settings);
    }

    private function defineExtensionStubs(): void
    {
        if (false === class_exists('FacturaScripts\\Core\\Tools', false)) {
            eval('namespace FacturaScripts\Core; class Tools { public static function config(string $name) { return ""; } }');
        }

        if (false === class_exists('FacturaScripts\\Dinamic\\Lib\\AssetManager', false)) {
            eval('namespace FacturaScripts\Dinamic\Lib; class AssetManager { public static function addCss(string $asset): void {} public static function addJs(string $asset): void {} }');
        }
    }
}
