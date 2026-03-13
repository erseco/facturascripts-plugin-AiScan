<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\AiScan\Extension\Controller;

use Closure;

class EditFacturaProveedor
{
    public function createViews(): Closure
    {
        return function () {
            $this->addButton('EditFacturaProveedor', [
                'action' => 'aiscan',
                'color' => 'info',
                'icon' => 'fa-solid fa-file-invoice',
                'label' => 'scan-invoice',
                'type' => 'modal',
                'target' => 'modal-aiscan',
            ]);
        };
    }

    public function loadData(): Closure
    {
        return function (string $viewName, $view) {
            if ($viewName === 'EditFacturaProveedor') {
                $invoiceId = $view->model->primaryColumnValue();
                if ($invoiceId !== null) {
                    $this->setSettings('EditFacturaProveedor', 'aiscan_invoice_id', $invoiceId);
                }
            }
        };
    }
}
