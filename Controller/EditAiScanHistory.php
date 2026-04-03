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

use FacturaScripts\Core\Lib\ExtendedController\PanelController;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\AiScan\Model\AiScanImportDocument;

class EditAiScanHistory extends PanelController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'purchases';
        $data['title'] = 'aiscan-import-batch-detail';
        $data['icon'] = 'fa-solid fa-clock-rotate-left';
        $data['showonmenu'] = false;
        return $data;
    }

    protected function createViews(): void
    {
        $this->addEditView(
            'EditAiScanImportBatch',
            'AiScanImportBatch',
            'aiscan-import-batch',
            'fa-solid fa-layer-group'
        );
        $this->setSettings('EditAiScanImportBatch', 'btnDelete', false);
        $this->setSettings('EditAiScanImportBatch', 'btnNew', false);

        $this->addEditListView(
            'EditAiScanImportDocument',
            'AiScanImportDocument',
            'aiscan-import-documents',
            'fa-solid fa-file-invoice'
        );
        $this->setSettings('EditAiScanImportDocument', 'btnDelete', false);
        $this->setSettings('EditAiScanImportDocument', 'btnNew', false);

        $this->addEditListView(
            'EditAiScanImportLine',
            'AiScanImportLine',
            'aiscan-import-lines',
            'fa-solid fa-list'
        );
        $this->setSettings('EditAiScanImportLine', 'btnDelete', false);
        $this->setSettings('EditAiScanImportLine', 'btnNew', false);
    }

    protected function loadData($viewName, $view)
    {
        $mvn = $this->getMainViewName();

        switch ($viewName) {
            case $mvn:
                parent::loadData($viewName, $view);
                break;

            case 'EditAiScanImportDocument':
                $idbatch = $this->getViewModelValue($mvn, 'id');
                $where = [Where::eq('idbatch', $idbatch)];
                $view->loadData('', $where, ['id' => 'ASC']);
                break;

            case 'EditAiScanImportLine':
                $idbatch = $this->getViewModelValue($mvn, 'id');
                $doc = new AiScanImportDocument();
                $docs = $doc->all([Where::eq('idbatch', $idbatch)], [], 0, 0);
                $docIds = array_map(fn ($d) => $d->id, $docs);
                if (empty($docIds)) {
                    $docIds = [0];
                }
                $where = [Where::in('iddocument', $docIds)];
                $view->loadData('', $where, ['iddocument' => 'ASC', 'sortorder' => 'ASC']);
                break;
        }
    }
}
