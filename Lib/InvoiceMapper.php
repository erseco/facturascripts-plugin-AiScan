<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\AiScan\Lib;

use FacturaScripts\Core\Model\Divisa;
use FacturaScripts\Core\Model\FacturaProveedor;
use FacturaScripts\Core\Model\LineaFacturaProveedor;
use FacturaScripts\Core\Model\Proveedor;

class InvoiceMapper
{
    public function mapToInvoice(array $extractedData, ?int $invoiceId = null): array
    {
        $result = ['success' => false, 'invoice_id' => null, 'errors' => []];

        try {
            if ($invoiceId) {
                $invoice = new FacturaProveedor();
                if (!$invoice->loadFromCode($invoiceId)) {
                    $result['errors'][] = 'Invoice not found: ' . $invoiceId;
                    return $result;
                }
            } else {
                $invoice = new FacturaProveedor();
            }

            $invoiceData = $extractedData['invoice'] ?? [];
            $supplierData = $extractedData['supplier'] ?? [];
            $lines = $extractedData['lines'] ?? [];

            if (!empty($supplierData['matched_supplier_id'])) {
                $supplier = new Proveedor();
                if ($supplier->loadFromCode($supplierData['matched_supplier_id'])) {
                    $invoice->setSubject($supplier);
                }
            }

            if (!empty($invoiceData['number'])) {
                $invoice->numproveedor = $invoiceData['number'];
            }

            if (!empty($invoiceData['issue_date'])) {
                $invoice->fecha = $invoiceData['issue_date'];
            }

            if (!empty($invoiceData['due_date'])) {
                $invoice->vencimiento = $invoiceData['due_date'];
            }

            if (!empty($invoiceData['currency'])) {
                $divisa = new Divisa();
                if ($divisa->loadFromCode(strtoupper($invoiceData['currency']))) {
                    $invoice->coddivisa = $divisa->coddivisa;
                }
            }

            if (!empty($invoiceData['notes'])) {
                $invoice->observaciones = $invoiceData['notes'];
            }

            if (!$invoice->save()) {
                $result['errors'][] = 'Failed to save invoice';
                return $result;
            }

            if ($invoiceId) {
                foreach ($invoice->getLines() as $line) {
                    $line->delete();
                }
            }

            foreach ($lines as $lineData) {
                $line = new LineaFacturaProveedor();
                $line->idfactura = $invoice->idfactura;
                $line->descripcion = $lineData['description'] ?? '';
                $line->cantidad = (float) ($lineData['quantity'] ?? 1);
                $line->pvpunitario = (float) ($lineData['unit_price'] ?? 0);
                $line->dtopor = (float) ($lineData['discount'] ?? 0);

                if (!empty($lineData['tax_rate'])) {
                    $line->iva = (float) $lineData['tax_rate'];
                }

                if (!$line->save()) {
                    $result['errors'][] = 'Failed to save line: ' . $line->descripcion;
                }
            }

            $invoice->updateAmounts();

            $result['success'] = true;
            $result['invoice_id'] = $invoice->idfactura;
        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }
}
