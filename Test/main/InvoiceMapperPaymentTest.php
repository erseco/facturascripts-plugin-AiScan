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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use PHPUnit\Framework\TestCase;

/**
 * Issue #57: forma de pago contado/tarjeta debe dejar la factura pagada
 * y el vencimiento en la fecha de la factura.
 */
final class InvoiceMapperPaymentTest extends TestCase
{
    /** @var FacturaProveedor[] */
    private array $invoicesToDelete = [];

    /** @var Proveedor[] */
    private array $suppliersToDelete = [];

    /** @var FormaPago[] */
    private array $paymentMethodsToDelete = [];

    public static function setUpBeforeClass(): void
    {
        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'FacturaScripts\\Dinamic\\')) {
                $coreClass = str_replace('\\Dinamic\\', '\\Core\\', $class);
                if (!class_exists($class, false) && class_exists($coreClass)) {
                    class_alias($coreClass, $class);
                }
            }
        }, true, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->invoicesToDelete as $invoice) {
            if ($invoice->exists()) {
                foreach ($invoice->getReceipts() as $receipt) {
                    $receipt->delete();
                }
                foreach ($invoice->getLines() as $line) {
                    $line->delete();
                }
                $invoice->delete();
            }
        }

        foreach ($this->suppliersToDelete as $supplier) {
            if ($supplier->exists()) {
                $address = $supplier->getDefaultAddress();
                if ($address->exists()) {
                    $address->delete();
                }
                $supplier->delete();
            }
        }

        foreach ($this->paymentMethodsToDelete as $method) {
            if ($method->exists()) {
                $method->delete();
            }
        }

        MiniLog::clear();
    }

    /**
     * @testdox Contado con plazo 0 deja la factura pagada aunque FormaPago.pagado sea false
     *
     * Reproduce el caso real del seed de FacturaScripts: CONT tiene
     * plazovencimiento=0 y pagado=0, y sin el arreglo AiScan deja pagada=no.
     */
    public function testContadoConPlazoCeroMarcaFacturaPagada(): void
    {
        $method = $this->createPaymentMethod([
            'descripcion' => 'AiScan Contado test',
            'pagado' => false,
            'plazovencimiento' => 0,
            'tipovencimiento' => 'days',
        ]);
        $supplier = $this->createSupplier($method->codpago);

        $result = $this->importInvoice($supplier->codproveedor, $method->codpago, '2026-06-01', '2026-07-01');
        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));

        $invoice = $this->reloadInvoice($result['invoice_id']);
        $this->assertTrue((bool) $invoice->pagada, 'La factura con contado (plazo 0) debe quedar pagada');

        $receipts = $invoice->getReceipts();
        $this->assertNotEmpty($receipts, 'Debe generarse al menos un recibo');
        foreach ($receipts as $receipt) {
            $this->assertTrue((bool) $receipt->pagado, 'Los recibos de contado deben quedar pagados');
            $this->assertSame(
                Tools::date('2026-06-01'),
                $receipt->vencimiento,
                'El vencimiento del recibo de contado debe ser la fecha de la factura'
            );
        }
    }

    /**
     * @testdox Tarjeta (FormaPago.pagado=true) deja la factura pagada
     */
    public function testTarjetaConFlagPagadoMarcaFacturaPagada(): void
    {
        $method = $this->createPaymentMethod([
            'descripcion' => 'AiScan Tarjeta test',
            'pagado' => true,
            'plazovencimiento' => 0,
            'tipovencimiento' => 'days',
        ]);
        $supplier = $this->createSupplier($method->codpago);

        $result = $this->importInvoice($supplier->codproveedor, $method->codpago, '2026-06-15');
        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));

        $invoice = $this->reloadInvoice($result['invoice_id']);
        $this->assertTrue((bool) $invoice->pagada);
        foreach ($invoice->getReceipts() as $receipt) {
            $this->assertTrue((bool) $receipt->pagado);
            $this->assertSame(Tools::date('2026-06-15'), $receipt->vencimiento);
        }
    }

    /**
     * @testdox Transferencia a 30 días no se marca como pagada
     */
    public function testTransferenciaAPlazoNoSeMarcaPagada(): void
    {
        $method = $this->createPaymentMethod([
            'descripcion' => 'AiScan Transfer test',
            'pagado' => false,
            'plazovencimiento' => 30,
            'tipovencimiento' => 'days',
        ]);
        $supplier = $this->createSupplier($method->codpago);

        $result = $this->importInvoice($supplier->codproveedor, $method->codpago, '2026-06-01');
        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));

        $invoice = $this->reloadInvoice($result['invoice_id']);
        $this->assertFalse((bool) $invoice->pagada, 'Una transferencia a plazo no debe quedar pagada');
        $receipts = $invoice->getReceipts();
        $this->assertNotEmpty($receipts);
        foreach ($receipts as $receipt) {
            $this->assertFalse((bool) $receipt->pagado);
            $this->assertSame(
                Tools::date('2026-07-01'),
                $receipt->vencimiento,
                'El vencimiento del recibo debe respetar el plazo de 30 días'
            );
        }
    }

    /**
     * @testdox Si la IA envía un vencimiento lejano, contado lo corrige a la fecha de factura
     */
    public function testContadoIgnoraVencimientoLejanoDeLaIA(): void
    {
        $method = $this->createPaymentMethod([
            'descripcion' => 'AiScan Contado override',
            'pagado' => false,
            'plazovencimiento' => 0,
            'tipovencimiento' => 'days',
        ]);
        $supplier = $this->createSupplier($method->codpago);

        $result = $this->importInvoice(
            $supplier->codproveedor,
            $method->codpago,
            '2026-03-10',
            '2026-12-31'
        );
        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));

        $invoice = $this->reloadInvoice($result['invoice_id']);
        $this->assertTrue((bool) $invoice->pagada);
        foreach ($invoice->getReceipts() as $receipt) {
            $this->assertSame(
                Tools::date('2026-03-10'),
                $receipt->vencimiento,
                'Contado debe ignorar un vencimiento lejano enviado por la IA'
            );
            $this->assertTrue((bool) $receipt->pagado);
        }
    }

    private function importInvoice(
        string $supplierCode,
        string $codpago,
        string $issueDate,
        ?string $dueDate = null
    ): array {
        $invoiceData = [
            'number' => 'PAY-' . mt_rand(10000, 99999),
            'issue_date' => $issueDate,
            'currency' => 'EUR',
            'codpago' => $codpago,
            'summary' => 'Test forma de pago #57',
        ];
        if ($dueDate !== null) {
            $invoiceData['due_date'] = $dueDate;
        }

        $mapper = new InvoiceMapper();
        $result = $mapper->mapToInvoice([
            'invoice' => $invoiceData,
            'supplier' => [
                'matched_supplier_id' => $supplierCode,
                'match_status' => 'matched',
            ],
            'lines' => [[
                'description' => 'Servicio de prueba',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 21,
            ]],
        ], null, 'lines', false);

        if (!empty($result['invoice_id'])) {
            $this->invoicesToDelete[] = $this->reloadInvoice($result['invoice_id']);
        }

        return $result;
    }

    private function reloadInvoice(int $invoiceId): FacturaProveedor
    {
        $invoice = new FacturaProveedor();
        $this->assertTrue($invoice->loadFromCode($invoiceId), 'No se pudo recargar la factura');
        return $invoice;
    }

    private function createPaymentMethod(array $data): FormaPago
    {
        $method = new FormaPago();
        $method->codpago = 'T' . mt_rand(1000, 9999);
        $method->descripcion = $data['descripcion'];
        $method->pagado = (bool) $data['pagado'];
        $method->plazovencimiento = (int) $data['plazovencimiento'];
        $method->tipovencimiento = $data['tipovencimiento'] ?? 'days';
        $method->activa = true;
        $this->assertTrue($method->save(), 'No se pudo crear la forma de pago de prueba');
        $this->paymentMethodsToDelete[] = $method;

        return $method;
    }

    private function createSupplier(string $codpago): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = 'AiScan Pago ' . mt_rand(10000, 99999);
        $supplier->razonsocial = $supplier->nombre;
        $supplier->cifnif = 'Z' . mt_rand(10000000, 99999999);
        $supplier->personafisica = false;
        $supplier->codpago = $codpago;

        if (empty($supplier->codserie)) {
            $series = (new Serie())->all([], [], 0, 1);
            if (empty($series)) {
                $newSerie = new Serie();
                $newSerie->codserie = 'A';
                $newSerie->descripcion = 'Serie A';
                $newSerie->save();
                $series = [$newSerie];
            }
            $supplier->codserie = $series[0]->codserie;
        }

        $this->assertTrue($supplier->save(), 'No se pudo crear el proveedor de prueba');
        $this->suppliersToDelete[] = $supplier;

        return $supplier;
    }
}
