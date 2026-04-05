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

namespace FacturaScripts\Plugins\AiScan\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

class ListAiScanHistory extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'purchases';
        $data['title'] = 'aiscan-import-history';
        $data['icon'] = 'fa-solid fa-clock-rotate-left';
        $data['showonmenu'] = false;
        return $data;
    }

    protected function createViews(): void
    {
        $this->createViewBatches();
    }

    private function createViewBatches(): void
    {
        $this->addView(
            'ListAiScanImportBatch',
            'AiScanImportBatch',
            'aiscan-import-batches',
            'fa-solid fa-layer-group'
        );
        $this->addSearchFields('ListAiScanImportBatch', ['nick', 'provider', 'importmode']);
        $this->addOrderBy('ListAiScanImportBatch', ['created_at'], 'date', 2);
        $this->addOrderBy('ListAiScanImportBatch', ['totaldocuments'], 'aiscan-total-documents');
        $this->addOrderBy('ListAiScanImportBatch', ['importedcount'], 'aiscan-imported');
        $this->addFilterSelect(
            'ListAiScanImportBatch',
            'importmode',
            'aiscan-import-mode',
            'importmode',
            [
                ['code' => '', 'description' => '------'],
                ['code' => 'lines', 'description' => 'aiscan-mode-lines'],
                ['code' => 'total', 'description' => 'aiscan-mode-total'],
            ]
        );
    }
}
