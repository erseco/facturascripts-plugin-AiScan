<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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
        $this->views[$viewName]->disableColumn('name', false, 'false');
    }

    protected function loadData(string $viewName, $view): void
    {
        if ($viewName === 'AiScanConfig') {
            $view->loadData('AiScan');
        }
    }
}
