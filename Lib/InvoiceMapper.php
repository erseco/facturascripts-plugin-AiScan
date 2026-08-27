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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\DataSrc\Impuestos;
use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Lib\ReceiptGenerator;
use FacturaScripts\Core\Lib\RegimenIVA;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\Divisa;
use FacturaScripts\Dinamic\Model\EstadoDocumento;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Plugins\AiScan\Model\AiScanSupplierProduct;
use RuntimeException;

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
        $database = new DataBase();
        $inTransaction = false;
        $invoice = null;

        try {
            // Issue #78: require complete identity data only for new imports.
            // Existing invoices may be updated with a partial payload and keep
            // their current supplier, invoice number and date.
            if ($invoiceId === null) {
                $blocking = (new SchemaValidator())->getImportBlockingErrors($extractedData);
                if ($blocking !== []) {
                    $result['errors'] = $blocking;
                    return $result;
                }
            }

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

            // Una factura nueva necesita idfactura para poder colgarle las líneas,
            // así que su cabecera se guarda ya. Si algo falla después se borra
            // entera. La de una factura existente se guarda más abajo, dentro de
            // la transacción (issue #93): si las líneas no se pueden grabar
            // tampoco debe quedarse con el número, la fecha o las observaciones
            // nuevos.
            if ($invoiceId === null && false === $invoice->save()) {
                $result['errors'][] = $this->readLogDetail() ?: Tools::lang()->trans('record-save-error');
                return $result;
            }

            $taxes = $extractedData['taxes'] ?? [];
            // Issue #69: en modo total siempre se usa la línea agregada con el
            // producto por defecto del proveedor. Antes se caía a buildLinesMode
            // cuando la IA/UI mandaba líneas (casi siempre), y el pin no se aplicaba
            // de forma predecible en total mode.
            $invoiceLines = $importMode === 'total'
                ? $this->buildTotalModeLines($invoice, $invoiceData, $taxes, $supplier)
                : $this->buildLinesMode($invoice, $lines, $invoiceData, $taxes, $supplier);

            // Issue #93: reimportar sobre una factura existente tiene que ser
            // atómico de principio a fin. Antes se guardaba la cabecera y se
            // borraban las líneas antiguas antes de saber si las nuevas se
            // podían grabar, así que un fallo la dejaba sin líneas y con el
            // número, la fecha y las observaciones ya cambiados.
            //
            // Las líneas se construyen antes de abrir la transacción para que la
            // creación diferida de tablas de FacturaScripts (que no admite DDL
            // dentro de una transacción) no la rompa. En una factura nueva no
            // hace falta transacción: si algo falla se borra la cabecera y la
            // clave ajena se lleva por delante las líneas que se hubieran
            // grabado.
            $oldLines = $invoiceId ? $invoice->getLines() : [];
            $inTransaction = false;

            // Si ya hay una transacción abierta por quien nos llama, es suya:
            // ni la empezamos ni la cerramos, pero nos protege igual. Si no hay
            // y no se puede abrir, se aborta antes de tocar nada: sin ella los
            // cambios dejarían de ser reversibles.
            if ($invoiceId && false === $database->inTransaction()) {
                if (false === $database->beginTransaction()) {
                    throw new RuntimeException(Tools::lang()->trans('aiscan-transaction-error'));
                }

                $inTransaction = true;
            }

            if ($invoiceId && false === $invoice->save()) {
                $result['errors'][] = $this->readLogDetail() ?: Tools::lang()->trans('record-save-error');

                if ($inTransaction) {
                    $database->rollback();
                    $inTransaction = false;
                }

                return $result;
            }

            foreach ($oldLines as $oldLine) {
                $oldLine->delete();
            }

            if (empty($invoiceLines) || false === Calculator::calculate($invoice, $invoiceLines, true)) {
                $message = Tools::lang()->trans('aiscan-failed-to-calculate-invoice-lines');
                $detail = $this->readLogDetail();
                $result['errors'][] = $detail === '' ? $message : $message . ' ' . $detail;

                if ($inTransaction) {
                    $database->rollback();
                    $inTransaction = false;
                }

                $this->discardDraft($invoiceId, $invoice);
                return $result;
            }

            if ($inTransaction) {
                $inTransaction = false;
                if (false === $database->commit()) {
                    $database->rollback();
                    $result['errors'][] = Tools::lang()->trans('aiscan-transaction-error');
                    return $result;
                }
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
            if ($inTransaction) {
                $database->rollback();
            }

            $this->discardDraft($invoiceId, $invoice);
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
            $line->cantidad = $this->resolveLineQuantity($lineData);
            $line->pvpunitario = (float) ($lineData['unit_price'] ?? $lineData['pvpunitario'] ?? $line->pvpunitario);
            $line->dtopor = (float) ($lineData['discount'] ?? $lineData['dtopor'] ?? 0);
            if (abs($line->dtopor) < 0.0000001) {
                $discountAmount = (float) ($lineData['dtoimporte'] ?? $lineData['discount_amount'] ?? 0);
                $lineBase = $line->cantidad * $line->pvpunitario;
                if (abs($discountAmount) > 0.0000001 && abs($lineBase) > 0.0000001) {
                    $line->dtopor = ($discountAmount / $lineBase) * 100;
                }
            }

            $taxRate = $lineData['tax_rate'] ?? $lineData['iva'] ?? null;
            if ($taxRate !== null && $taxRate !== '') {
                $line->iva = (float) $taxRate;
            }

            $taxCode = trim((string) ($lineData['codimpuesto'] ?? $lineData['tax_code'] ?? ''));
            if ($taxCode !== '') {
                $line->codimpuesto = $this->resolveTaxCode($taxCode, (float) $line->iva, $line->descripcion);
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

            // Issue #93: la IA a veces devuelve el texto legal completo
            // ("Art. 50.Uno.27 Ley 4/2012") en lugar del código de excepción.
            // La columna es varchar(20) y la línea no se podría grabar, así que
            // solo se acepta un código de la lista del core.
            $taxException = trim((string) ($lineData['excepcioniva'] ?? ''));
            if (array_key_exists($taxException, RegimenIVA::allExceptions())) {
                $line->excepcioniva = $taxException;
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

    /**
     * Issue #93: la IA puede devolver un codimpuesto que no existe en la
     * instalación (p. ej. "IGICEXENTO" en una factura exenta de IGIC). Guardarlo
     * rompe la clave ajena contra `impuestos`: la línea no se graba, el import
     * falla y queda una factura en boceto a 0 €.
     *
     * El respaldo tiene que ser inequívoco. Buscar por tipo no vale: IVA4 e IPSI4
     * comparten el 4 % (y IGIC0, IPSI0 e IVA0 el 0 %), así que el orden
     * alfabético de `Impuestos::all()` colaría un impuesto de otro régimen
     * fiscal. Y un único candidato tampoco basta: en una instalación estándar el
     * 7 % solo lo tiene IGIC7, que no sirve para una empresa con IVA.
     *
     * Por eso se descartan primero los impuestos que no son de la operación del
     * impuesto predeterminado de la empresa, y solo se acepta el respaldo si
     * queda exactamente uno. Si queda ninguno o varios, se corta el import.
     *
     * @throws RuntimeException si el impuesto no se puede determinar
     */
    private function resolveTaxCode(string $code, float $rate, string $description): string
    {
        if (!empty(Impuestos::get($code)->codimpuesto)) {
            return $code;
        }

        $candidates = [];
        foreach (Impuestos::all() as $tax) {
            if (abs((float) $tax->iva - $rate) < 0.001) {
                $candidates[] = $tax;
            }
        }

        $operation = Impuestos::default()->operacion;
        if (!empty($operation)) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn ($tax): bool => $tax->operacion === $operation
            ));
        }

        if (count($candidates) === 1) {
            return $candidates[0]->codimpuesto;
        }

        $message = Tools::lang()->trans('aiscan-unresolved-tax-code', [
            '%code%' => $code,
            '%rate%' => Tools::number($rate),
            '%description%' => $description,
        ]);
        if (!str_contains($message, $code)) {
            $message .= ': ' . $code;
        }

        throw new RuntimeException($message);
    }

    /**
     * Issue #93: la cabecera se guarda antes que las líneas. Si el import falla
     * después, hay que deshacerla o quedaría un boceto a 0 € huérfano. Solo se
     * borra la factura que hemos creado nosotros; una existente no se toca.
     */
    private function discardDraft(?int $invoiceId, ?FacturaProveedor $invoice): void
    {
        if ($invoiceId === null && $invoice instanceof FacturaProveedor && $invoice->exists()) {
            $invoice->delete();
        }
    }

    private function readLogDetail(): string
    {
        $miniLog = Tools::log()::read('', ['critical', 'error', 'warning']);
        return implode('; ', array_map(fn ($m) => $m['message'], $miniLog));
    }

    /**
     * Quantity 0 is valid (issue #82: prepaid/credit lines the user zeros out).
     * Only default to 1 when the field is absent.
     */
    private function resolveLineQuantity(array $lineData): float
    {
        if (array_key_exists('cantidad', $lineData) && $lineData['cantidad'] !== '' && $lineData['cantidad'] !== null) {
            return (float) $lineData['cantidad'];
        }

        if (array_key_exists('quantity', $lineData) && $lineData['quantity'] !== '' && $lineData['quantity'] !== null) {
            return (float) $lineData['quantity'];
        }

        return 1.0;
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
