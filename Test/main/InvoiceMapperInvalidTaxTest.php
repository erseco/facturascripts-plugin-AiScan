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
use FacturaScripts\Core\DataSrc\Impuestos;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use PHPUnit\Framework\TestCase;

/**
 * Issue #93: valores inventados por la IA en la línea (codimpuesto inexistente,
 * excepción de IVA con el texto legal completo) rompían el guardado y dejaban
 * una factura en boceto a 0 €.
 */
final class InvoiceMapperInvalidTaxTest extends TestCase
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

    public function testUnknownTaxCodeFallsBackToAnExistingOne(): void
    {
        $supplier = $this->createSupplier();

        $result = (new InvoiceMapper())->mapToInvoice(
            $this->buildData($supplier, ['codimpuesto' => 'IGICEXENTO', 'iva' => 0]),
            null,
            'lines',
            false
        );

        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));

        $invoice = new FacturaProveedor();
        $this->assertTrue($invoice->loadFromCode($result['invoice_id']));
        $this->invoicesToDelete[] = $invoice;

        $lines = $invoice->getLines();
        $this->assertCount(1, $lines);
        $this->assertNotEmpty(
            Impuestos::get((string) $lines[0]->codimpuesto)->codimpuesto,
            'El impuesto de la línea debe existir en la instalación'
        );
        $this->assertEqualsWithDelta(0.0, (float) $lines[0]->iva, 0.001);
        $this->assertEqualsWithDelta(30.0, (float) $invoice->total, 0.01);
    }

    public function testOverlongTaxExceptionIsIgnored(): void
    {
        $supplier = $this->createSupplier();

        $result = (new InvoiceMapper())->mapToInvoice(
            $this->buildData($supplier, [
                'codimpuesto' => 'IGIC0',
                'iva' => 0,
                'excepcioniva' => 'Art. 50.Uno.27 de la Ley 4/2012',
            ]),
            null,
            'lines',
            false
        );

        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));

        $invoice = new FacturaProveedor();
        $this->assertTrue($invoice->loadFromCode($result['invoice_id']));
        $this->invoicesToDelete[] = $invoice;

        $this->assertEmpty($invoice->getLines()[0]->excepcioniva);
    }

    public function testFailedLinesLeaveNoDraftInvoiceBehind(): void
    {
        $supplier = $this->createSupplier();

        $result = (new InvoiceMapper())->mapToInvoice(
            $this->buildData($supplier, [
                'iva' => 0,
                'referencia' => 'REF-DEMASIADO-LARGA-PARA-LA-COLUMNA-DE-30',
            ]),
            null,
            'lines',
            false
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['invoice_id']);

        $where = [Where::eq('codproveedor', $supplier->codproveedor)];
        $this->assertSame(
            0,
            (new FacturaProveedor())->count($where),
            'No debe quedar ninguna factura en boceto cuando fallan las líneas'
        );
    }

    private function buildData(Proveedor $supplier, array $line): array
    {
        return [
            'invoice' => [
                'number' => 'RFAC-' . mt_rand(10000, 99999),
                'issue_date' => '2026-08-25',
                'currency' => 'EUR',
                'subtotal' => 30.0,
                'tax_amount' => 0.0,
                'total' => 30.0,
            ],
            'supplier' => [
                'matched_supplier_id' => $supplier->codproveedor,
                'match_status' => 'matched',
            ],
            'lines' => [array_merge([
                'descripcion' => 'ONA MESITA NOCHE 1 CAJON 1 HUECO BLANCO',
                'cantidad' => 2,
                'pvpunitario' => 15.0,
                'dtopor' => 0,
            ], $line)],
        ];
    }

    private function resolvePaymentMethodCode(): string
    {
        $existing = (new FormaPago())->all([], [], 0, 1);
        if (!empty($existing)) {
            return (string) $existing[0]->codpago;
        }

        $method = new FormaPago();
        $method->codpago = 'T' . mt_rand(1000, 9999);
        $method->descripcion = 'AiScan Tax93 test';
        $method->pagado = false;
        $method->plazovencimiento = 0;
        $method->tipovencimiento = 'days';
        $method->activa = true;
        $this->assertTrue($method->save(), 'No se pudo crear la forma de pago de prueba');
        $this->paymentMethodsToDelete[] = $method;

        return (string) $method->codpago;
    }

    private function createSupplier(): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = 'AiScan Tax93 ' . mt_rand(10000, 99999);
        $supplier->razonsocial = $supplier->nombre;
        $supplier->cifnif = 'Y' . mt_rand(10000000, 99999999);
        $supplier->personafisica = false;
        $supplier->codpago = $this->resolvePaymentMethodCode();

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
