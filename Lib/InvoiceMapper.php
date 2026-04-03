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

use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Divisa;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Plugins\AiScan\Model\AiScanSupplierProduct;

class InvoiceMapper
{
    public function __construct(
        private readonly AttachmentService $attachmentService = new AttachmentService(),
        private readonly ProductMatcher $productMatcher = new ProductMatcher(),
        private readonly SupplierService $supplierService = new SupplierService()
    ) {
    }

    public function mapToInvoice(
        array $extractedData,
        ?int $invoiceId = null,
        string $importMode = 'lines'
    ): array {
        $result = ['success' => false, 'invoice_id' => null, 'errors' => []];

        try {
            if ($invoiceId) {
                $invoice = new FacturaProveedor();
                if (!$invoice->loadFromCode($invoiceId)) {
                    $result['errors'][] = Tools::lang()->trans(
                        'aiscan-invoice-not-found',
                        ['%invoiceId%' => (string) $invoiceId]
                    );
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

            $invoiceLines = $importMode === 'total'
                ? $this->buildTotalModeLine($invoice, $invoiceData, $supplier)
                : $this->buildLinesMode($invoice, $lines, $invoiceData);

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

    private function buildLinesMode(FacturaProveedor $invoice, array $lines, array $invoiceData): array
    {
        $preparedLines = $this->prepareLines($lines, $invoiceData);
        $invoiceLines = [];

        foreach ($preparedLines as $lineData) {
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

        return $invoiceLines;
    }

    private function buildTotalModeLine(
        FacturaProveedor $invoice,
        array $invoiceData,
        ?Proveedor $supplier
    ): array {
        $reference = null;
        if ($supplier) {
            $defaultProduct = AiScanSupplierProduct::getForSupplier($supplier->codproveedor);
            if ($defaultProduct) {
                $reference = $defaultProduct->referencia;
            }
        }

        $line = $reference ? $invoice->getNewProductLine($reference) : $invoice->getNewLine();
        $line->descripcion = $this->fallbackDescription($invoiceData);
        $line->cantidad = 1;
        $line->pvpunitario = $this->fallbackSubtotal($invoiceData);
        $line->dtopor = 0;
        $line->iva = $this->computeTaxRate($invoiceData);

        return [$line];
    }

    private function prepareLines(array $lines, array $invoiceData): array
    {
        if (!empty($lines)) {
            return $lines;
        }

        return [[
            'description' => $this->fallbackDescription($invoiceData),
            'quantity' => 1,
            'unit_price' => $this->fallbackSubtotal($invoiceData),
            'discount' => 0,
            'tax_rate' => $this->computeTaxRate($invoiceData),
        ]];
    }

    private function computeTaxRate(array $invoiceData): float
    {
        $subtotal = (float) ($invoiceData['subtotal'] ?? $invoiceData['total'] ?? 0);
        $taxAmount = (float) ($invoiceData['tax_amount'] ?? 0);
        return $subtotal > 0 && $taxAmount > 0
            ? round(($taxAmount / $subtotal) * 100, 2)
            : 0.0;
    }

    private function fallbackDescription(array $invoiceData): string
    {
        return trim((string) (
            $invoiceData['summary']
            ?? Tools::lang()->trans('aiscan-scanned-supplier-invoice')
        ));
    }

    private function fallbackSubtotal(array $invoiceData): float
    {
        $subtotal = (float) ($invoiceData['subtotal'] ?? $invoiceData['total'] ?? 0);
        return $subtotal > 0 ? $subtotal : (float) ($invoiceData['total'] ?? 0);
    }
}
