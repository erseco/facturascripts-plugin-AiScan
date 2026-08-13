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
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use PHPUnit\Framework\TestCase;

/**
 * Issue #82: cantidad 0 on a prepaid/credit line must be stored as 0, not 1.
 */
final class InvoiceMapperZeroQuantityTest extends TestCase
{
    /** @var FacturaProveedor[] */
    private array $invoicesToDelete = [];

    /** @var Proveedor[] */
    private array $suppliersToDelete = [];

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

        MiniLog::clear();
    }

    public function testMapToInvoicePreservesZeroQuantityOnPrepaidLine(): void
    {
        $supplier = $this->createSupplier();

        $result = (new InvoiceMapper())->mapToInvoice([
            'invoice' => [
                'number' => 'LM-' . mt_rand(10000, 99999),
                'issue_date' => '2026-08-12',
                'currency' => 'EUR',
                'subtotal' => 94.37,
                'tax_amount' => 0,
                'total' => 94.37,
            ],
            'supplier' => [
                'matched_supplier_id' => $supplier->codproveedor,
                'match_status' => 'matched',
            ],
            'lines' => [
                [
                    'description' => 'Compra rodapie',
                    'cantidad' => 20,
                    'pvpunitario' => 4.99,
                    'dtopor' => 5.41,
                    'iva' => 0,
                ],
                [
                    'description' => 'Ya pagado del pedido',
                    'cantidad' => 0,
                    'pvpunitario' => -94.37,
                    'dtopor' => 0,
                    'iva' => 0,
                ],
            ],
        ], null, 'lines', false);

        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));
        $this->assertNotNull($result['invoice_id']);

        $invoice = new FacturaProveedor();
        $this->assertTrue($invoice->loadFromCode($result['invoice_id']));
        $this->invoicesToDelete[] = $invoice;

        $lines = $invoice->getLines();
        $this->assertCount(2, $lines);

        $prepaid = null;
        foreach ($lines as $line) {
            if ($line->descripcion === 'Ya pagado del pedido') {
                $prepaid = $line;
                break;
            }
        }

        $this->assertNotNull($prepaid, 'Debe existir la línea de prepago');
        $this->assertEqualsWithDelta(0.0, (float) $prepaid->cantidad, 0.001);
        $this->assertEqualsWithDelta(-94.37, (float) $prepaid->pvpunitario, 0.01);
    }

    private function createSupplier(): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = 'AiScan Qty0 ' . mt_rand(10000, 99999);
        $supplier->razonsocial = $supplier->nombre;
        $supplier->cifnif = 'Y' . mt_rand(10000000, 99999999);
        $supplier->personafisica = false;

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
