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
use FacturaScripts\Core\Lib\ReceiptGenerator;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\Divisa;
use FacturaScripts\Dinamic\Model\EstadoDocumento;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Plugins\AiScan\Model\AiScanSupplierProduct;

class InvoiceMapper
{
    public function __construct(
        private readonly AttachmentService $attachmentService = new AttachmentService(),
        private readonly ProductMatcher $productMatcher = new ProductMatcher(),
        private readonly PurchaseLineInventoryUpdater $inventoryUpdater = new PurchaseLineInventoryUpdater(),
        private readonly SupplierService $supplierService = new SupplierService(),
        private readonly HistoricalContextService $historicalContext = new HistoricalContextService()
    ) {
    }

    public function mapToInvoice(
        array $extractedData,
        ?int $invoiceId = null,
        string $importMode = 'lines',
        bool $updateStockPurchaseData = false
    ): array {
        $result = ['success' => false, 'invoice_id' => null, 'errors' => [], 'warnings' => []];

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

            $resolvedFormaPago = null;
            if (!empty($invoiceData['codpago'])) {
                $formaPago = new FormaPago();
                if (!$formaPago->loadFromCode($invoiceData['codpago'])) {
                    $codpago = (string) $invoiceData['codpago'];
                    $message = Tools::lang()->trans(
                        'aiscan-invalid-payment-method',
                        ['%codpago%' => $codpago]
                    );
                    if (!str_contains($message, $codpago)) {
                        $message .= ': ' . $codpago;
                    }
                    $result['errors'][] = $message;
                    return $result;
                }
                $resolvedFormaPago = $formaPago;
            }

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

            // Nota: FacturaProveedor no tiene columna vencimiento; el vencimiento
            // real vive en los recibos (ReciboProveedor) y se aplica más abajo.

            if (!empty($invoiceData['currency'])) {
                $divisa = new Divisa();
                if ($divisa->loadFromCode(strtoupper($invoiceData['currency']))) {
                    $invoice->coddivisa = $divisa->coddivisa;
                }
            }

            if ($resolvedFormaPago instanceof FormaPago) {
                $invoice->codpago = $resolvedFormaPago->codpago;
            }

            $invoice->observaciones = $this->buildNotes($invoiceData);

            if (empty($invoice->codalmacen)) {
                $invoice->codalmacen = Tools::settings('default', 'codalmacen', '');
                if (empty($invoice->codalmacen)) {
                    $warehouse = new Almacen();
                    foreach ($warehouse->all([], [], 0, 1) as $first) {
                        $invoice->codalmacen = $first->codalmacen;
                    }
                }
            }

            if (!$invoice->save()) {
                $miniLog = Tools::log()::read('', ['critical', 'error', 'warning']);
                $detail = implode('; ', array_map(fn ($m) => $m['message'], $miniLog));
                $result['errors'][] = $detail ?: Tools::lang()->trans('record-save-error');
                return $result;
            }

            if ($invoiceId) {
                foreach ($invoice->getLines() as $line) {
                    $line->delete();
                }
            }

            $taxes = $extractedData['taxes'] ?? [];
            // Issue #69: en modo total siempre se usa la línea agregada con el
            // producto por defecto del proveedor. Antes se caía a buildLinesMode
            // cuando la IA/UI mandaba líneas (casi siempre), y el pin no se aplicaba
            // de forma predecible en total mode.
            $invoiceLines = $importMode === 'total'
                ? $this->buildTotalModeLines($invoice, $invoiceData, $taxes, $supplier)
                : $this->buildLinesMode($invoice, $lines, $invoiceData, $taxes, $supplier);

            if (empty($invoiceLines) || false === Calculator::calculate($invoice, $invoiceLines, true)) {
                $result['errors'][] = Tools::lang()->trans('aiscan-failed-to-calculate-invoice-lines');
                return $result;
            }

            // Tras calcular líneas FS genera recibos. Ajustamos vencimiento y
            // pagado según la forma de pago (issue #57: contado/tarjeta).
            if ($resolvedFormaPago instanceof FormaPago) {
                $this->applyPaymentMethodToReceipts($invoice, $resolvedFormaPago, $invoiceData);
            }

            $this->attachmentService->attachTemporaryFile($invoice, $extractedData['_upload'] ?? []);

            $this->setReceivedStatus($invoice);

            // Total mode aggregates lines by tax and has no linked products,
            // so stock/purchase-data updates do not apply (skip to avoid noise).
            if ($updateStockPurchaseData && $importMode !== 'total') {
                $updateResult = $this->inventoryUpdater->update($invoice, $lines);
                $result['warnings'] = $updateResult['warnings'];
            } else {
                $this->inventoryUpdater->revertAll($invoice);
            }

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

    private function buildLinesMode(
        FacturaProveedor $invoice,
        array $lines,
        array $invoiceData,
        array $taxes = [],
        ?Proveedor $supplier = null
    ): array {
        $preparedLines = $this->prepareLines($lines, $invoiceData, $taxes);
        $invoiceLines = [];

        // issue #53: fallback to the supplier's usual product for lines that
        // cannot be matched by SKU or description.
        $suggestedReference = null;
        if ($supplier) {
            $suggestion = $this->historicalContext->getSuggestedProduct($supplier->codproveedor);
            $suggestedReference = $suggestion['referencia'] ?? null;
        }

        foreach ($preparedLines as $lineData) {
            $reference = !empty($lineData['referencia'])
                ? $lineData['referencia']
                : $this->productMatcher->findReference($lineData, $suggestedReference);
            $line = $reference ? $invoice->getNewProductLine($reference) : $invoice->getNewLine();
            $line->actualizastock = 0;
            $desc = $lineData['description'] ?? $lineData['descripcion'] ?? $line->descripcion;
            $line->descripcion = trim((string) $desc);
            $line->cantidad = max(1, (float) ($lineData['quantity'] ?? $lineData['cantidad'] ?? 1));
            $line->pvpunitario = (float) ($lineData['unit_price'] ?? $lineData['pvpunitario'] ?? $line->pvpunitario);
            $line->dtopor = (float) ($lineData['discount'] ?? $lineData['dtopor'] ?? 0);

            if (!empty($lineData['codimpuesto'] ?? $lineData['tax_code'] ?? '')) {
                $line->codimpuesto = $lineData['codimpuesto'] ?? $lineData['tax_code'];
            }

            $taxRate = $lineData['tax_rate'] ?? $lineData['iva'] ?? null;
            if ($taxRate !== null && $taxRate !== '') {
                $line->iva = (float) $taxRate;
            }

            $irpf = $lineData['irpf'] ?? null;
            if ($irpf !== null && $irpf !== '') {
                $line->irpf = (float) $irpf;
            }

            $codret = $lineData['codretencion'] ?? $lineData['irpf_code'] ?? '';
            if (!empty($codret)) {
                $line->codretencion = $codret;
            }

            if (!empty($lineData['recargo'] ?? 0)) {
                $line->recargo = (float) $lineData['recargo'];
            }

            if (!empty($lineData['excepcioniva'] ?? '')) {
                $line->excepcioniva = $lineData['excepcioniva'];
            }

            if (!empty($lineData['suplido'] ?? false)) {
                $line->suplido = true;
            }

            $invoiceLines[] = $line;
        }

        return $invoiceLines;
    }

    private function buildTotalModeLines(
        FacturaProveedor $invoice,
        array $invoiceData,
        array $taxes,
        ?Proveedor $supplier
    ): array {
        // Preferir el pin del proveedor; si no hay, el histórico (#53 / #69).
        $reference = null;
        if ($supplier) {
            $defaultProduct = AiScanSupplierProduct::getForSupplier($supplier->codproveedor);
            if ($defaultProduct && !empty($defaultProduct->referencia)) {
                $reference = $defaultProduct->referencia;
            } else {
                $suggestion = $this->historicalContext->getSuggestedProduct($supplier->codproveedor);
                $reference = $suggestion['referencia'] ?? null;
            }
        }

        $description = $this->fallbackDescription($invoiceData);

        if (!empty($taxes) && count($taxes) > 1) {
            $invoiceLines = [];
            foreach ($taxes as $tax) {
                $line = $reference ? $invoice->getNewProductLine($reference) : $invoice->getNewLine();
                $line->descripcion = $description;
                $line->cantidad = 1;
                $line->pvpunitario = (float) ($tax['base'] ?? 0);
                $line->dtopor = 0;
                $line->iva = (float) ($tax['rate'] ?? 0);
                $invoiceLines[] = $line;
            }
            return $invoiceLines;
        }

        $line = $reference ? $invoice->getNewProductLine($reference) : $invoice->getNewLine();
        $line->descripcion = $description;
        $line->cantidad = 1;
        $line->pvpunitario = $this->fallbackSubtotal($invoiceData);
        $line->dtopor = 0;
        $line->iva = !empty($taxes) ? (float) ($taxes[0]['rate'] ?? 0) : $this->computeTaxRate($invoiceData);

        return [$line];
    }

    private function prepareLines(array $lines, array $invoiceData, array $taxes = []): array
    {
        if (!empty($lines)) {
            return $this->inferMissingTaxRate($lines, $invoiceData, $taxes);
        }

        return [[
            'description' => $this->fallbackDescription($invoiceData),
            'quantity' => 1,
            'unit_price' => $this->fallbackSubtotal($invoiceData),
            'discount' => 0,
            'tax_rate' => $this->computeTaxRate($invoiceData),
        ]];
    }

    /**
     * Issue #61: some AI extractions only report the tax rate in the invoice-level
     * `taxes` breakdown, leaving it out of the individual lines. Calculator then
     * books those lines at 0%, so the total looks right but the accounting entry
     * (asiento) does not reflect the tax. Infer the missing rate from the
     * breakdown, but only when it is unambiguous: a single tax entry whose
     * base/amount reconcile with the invoice totals and with the sum of the
     * lines, and no suplido lines (which are excluded from the taxable base).
     */
    private function inferMissingTaxRate(array $lines, array $invoiceData, array $taxes): array
    {
        if (count($taxes) !== 1) {
            return $lines;
        }

        $tax = $taxes[0];
        $rate = (float) ($tax['rate'] ?? 0);
        $base = (float) ($tax['base'] ?? 0);
        $amount = (float) ($tax['amount'] ?? 0);
        $tolerance = 0.01;

        if (abs($amount) < 0.001) {
            return $lines;
        }

        $subtotal = (float) ($invoiceData['subtotal'] ?? 0);
        $taxAmount = (float) ($invoiceData['tax_amount'] ?? 0);
        if (abs($base - $subtotal) > $tolerance || abs($amount - $taxAmount) > $tolerance) {
            return $lines;
        }

        $linesSubtotal = 0.0;
        foreach ($lines as $line) {
            if (!empty($line['suplido'] ?? false)) {
                return $lines;
            }
            $quantity = (float) ($line['quantity'] ?? $line['cantidad'] ?? 1);
            $unitPrice = (float) ($line['unit_price'] ?? $line['pvpunitario'] ?? 0);
            $discount = (float) ($line['discount'] ?? $line['dtopor'] ?? 0);
            $linesSubtotal += $quantity * $unitPrice * (1 - $discount / 100);
        }

        if (abs($linesSubtotal - $base) > $tolerance) {
            return $lines;
        }

        foreach ($lines as &$line) {
            if (!$this->lineHasTaxInfo($line)) {
                $line['tax_rate'] = $rate;
            }
        }
        unset($line);

        return $lines;
    }

    private function lineHasTaxInfo(array $line): bool
    {
        $taxRate = $line['tax_rate'] ?? $line['iva'] ?? null;
        if ($taxRate !== null && $taxRate !== '') {
            return true;
        }

        return !empty($line['codimpuesto'] ?? $line['tax_code'] ?? '');
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

    private function setReceivedStatus(FacturaProveedor $invoice): void
    {
        $status = new EstadoDocumento();
        $where = [
            Where::column('tipodoc', 'FacturaProveedor'),
            Where::column('nombre', 'Recibida'),
        ];
        foreach ($status->all($where, [], 0, 1) as $received) {
            $invoice->idestado = $received->idestado;
            $invoice->save();
            return;
        }
    }

    /**
     * Decide si la forma de pago es inmediata (contado / tarjeta / "ya pagado").
     *
     * FacturaScripts usa FormaPago.pagado, pero el seed por defecto deja CONT
     * y TARJETA con pagado=false y plazovencimiento=0. Ese plazo 0 se trata
     * aquí como pago inmediato (issue #57).
     */
    private function isImmediatePayment(FormaPago $formaPago): bool
    {
        return (bool) $formaPago->pagado || (int) $formaPago->plazovencimiento === 0;
    }

    /**
     * Calcula el vencimiento de recibo según forma de pago y datos de la IA.
     *
     * - Inmediata: fecha de la factura
     * - A plazo con due_date de la IA: se respeta
     * - A plazo sin due_date: FormaPago::getExpiration()
     */
    private function resolveReceiptDueDate(
        FacturaProveedor $invoice,
        FormaPago $formaPago,
        array $invoiceData
    ): string {
        if ($this->isImmediatePayment($formaPago)) {
            return (string) $invoice->fecha;
        }

        $dueDate = trim((string) ($invoiceData['due_date'] ?? ''));
        if ($dueDate !== '') {
            return $dueDate;
        }

        return $formaPago->getExpiration((string) $invoice->fecha);
    }

    /**
     * Ajusta recibos (vencimiento + pagado) y sincroniza FacturaProveedor.pagada.
     *
     * En facturas de compra el vencimiento no está en la cabecera: vive en
     * ReciboProveedor. El flag pagada de la factura se recalcula desde los
     * importes de recibos pagados (ReceiptGenerator::update).
     */
    private function applyPaymentMethodToReceipts(
        FacturaProveedor $invoice,
        FormaPago $formaPago,
        array $invoiceData
    ): void {
        $dueDate = $this->resolveReceiptDueDate($invoice, $formaPago, $invoiceData);
        $isImmediate = $this->isImmediatePayment($formaPago);

        $receipts = $invoice->getReceipts();
        if (empty($receipts)) {
            $generator = new ReceiptGenerator();
            $generator->generate($invoice, 1);
            $receipts = $invoice->getReceipts();
        }

        foreach ($receipts as $receipt) {
            $changed = false;

            if ((string) $receipt->vencimiento !== $dueDate) {
                $receipt->vencimiento = $dueDate;
                $changed = true;
            }

            if ($isImmediate && !$receipt->pagado) {
                $receipt->pagado = true;
                if (empty($receipt->fechapago)) {
                    $receipt->fechapago = $invoice->fecha;
                }
                $changed = true;
            }

            if ($changed) {
                $receipt->disableInvoiceUpdate(true);
                $receipt->save();
            }
        }

        $generator = new ReceiptGenerator();
        $generator->update($invoice);
        $invoice->loadFromCode($invoice->idfactura);
    }
}
