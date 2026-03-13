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

namespace FacturaScripts\Plugins\AiScan\Controller;

use FacturaScripts\Core\Lib\ExtendedController\PanelController;

class AiScanConfig extends PanelController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'aiscan-config';
        $data['icon'] = 'fa-solid fa-robot';
        return $data;
    }

    protected function createViews(): void
    {
        $this->createViewsConfig();
    }

    protected function createViewsConfig(string $viewName = 'AiScanConfig'): void
    {
        $this->addEditView($viewName, 'Settings', 'aiscan-settings', 'fa-solid fa-gear');
        $this->views[$viewName]->disableColumn('name', false, false);
    }

    protected function loadData($viewName, $view)
    {
        if ($viewName === 'AiScanConfig') {
            $view->loadData('AiScan');
        }
    }
}
