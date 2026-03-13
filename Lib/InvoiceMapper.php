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

namespace FacturaScripts\Plugins\AiScan\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Dinamic\Model\Divisa;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\Proveedor;

class InvoiceMapper
{
    public function __construct(
        private readonly AttachmentService $attachmentService = new AttachmentService(),
        private readonly ProductMatcher $productMatcher = new ProductMatcher(),
        private readonly SupplierService $supplierService = new SupplierService()
    ) {
    }

    public function mapToInvoice(array $extractedData, ?int $invoiceId = null): array
    {
        $result = ['success' => false, 'invoice_id' => null, 'errors' => []];

        try {
            if ($invoiceId) {
                $invoice = new FacturaProveedor();
                if (!$invoice->loadFromCode($invoiceId)) {
                    $result['errors'][] = Tools::lang()->trans('aiscan-invoice-not-found', ['%invoiceId%' => (string) $invoiceId]);
                    return $result;
                }
            } else {
                $invoice = new FacturaProveedor();
            }

            $invoiceData = $extractedData['invoice'] ?? [];
            $supplierData = $extractedData['supplier'] ?? [];
            $lines = $extractedData['lines'] ?? [];

            $supplier = $this->supplierService->resolve($supplierData);
            if ($supplier instanceof Proveedor) {
                $invoice->setSubject($supplier);
            } elseif (empty($invoice->codproveedor)) {
                $result['errors'][] = Tools::lang()->trans('aiscan-supplier-not-matched-or-created');
                return $result;
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

            if (!empty($invoiceData['withholding_amount'])) {
                $invoice->totalirpf = (float) $invoiceData['withholding_amount'];
            }

            $invoice->observaciones = $this->buildNotes($invoiceData);

            if (!$invoice->save()) {
                $result['errors'][] = Tools::lang()->trans('record-save-error');
                return $result;
            }

            if ($invoiceId) {
                foreach ($invoice->getLines() as $line) {
                    $line->delete();
                }
            }

            $invoiceLines = [];
            foreach ($this->prepareLines($lines, $invoiceData) as $lineData) {
                $reference = $this->productMatcher->findReference($lineData);
                $line = $reference ? $invoice->getNewProductLine($reference) : $invoice->getNewLine();
                $line->descripcion = trim((string) ($lineData['description'] ?? $line->descripcion));
                $line->cantidad = max(1, (float) ($lineData['quantity'] ?? 1));
                $line->pvpunitario = (float) ($lineData['unit_price'] ?? $line->pvpunitario);
                $line->dtopor = (float) ($lineData['discount'] ?? 0);

                if (!empty($lineData['tax_rate'])) {
                    $line->iva = (float) $lineData['tax_rate'];
                }

                $invoiceLines[] = $line;
            }

            if (empty($invoiceLines) || false === Calculator::calculate($invoice, $invoiceLines, true)) {
                $result['errors'][] = Tools::lang()->trans('aiscan-failed-to-calculate-invoice-lines');
                return $result;
            }

            $this->attachmentService->attachTemporaryFile($invoice, $extractedData['_upload'] ?? []);

            $result['success'] = true;
            $result['invoice_id'] = $invoice->idfactura;
        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    private function buildNotes(array $invoiceData): string
    {
        $parts = [];
        foreach (['summary', 'payment_terms', 'notes'] as $field) {
            $value = trim((string) ($invoiceData[$field] ?? ''));
            if (!empty($value)) {
                $parts[] = $value;
            }
        }

        return implode("\n\n", array_unique($parts));
    }

    private function prepareLines(array $lines, array $invoiceData): array
    {
        if (!empty($lines)) {
            return $lines;
        }

        $subtotal = (float) ($invoiceData['subtotal'] ?? $invoiceData['total'] ?? 0);
        $taxAmount = (float) ($invoiceData['tax_amount'] ?? 0);
        $taxRate = $subtotal > 0 && $taxAmount > 0 ? round(($taxAmount / $subtotal) * 100, 2) : 0.0;

        return [[
            'description' => trim((string) ($invoiceData['summary'] ?? Tools::lang()->trans('aiscan-scanned-supplier-invoice'))),
            'quantity' => 1,
            'unit_price' => $subtotal > 0 ? $subtotal : (float) ($invoiceData['total'] ?? 0),
            'discount' => 0,
            'tax_rate' => $taxRate,
        ]];
    }
}
