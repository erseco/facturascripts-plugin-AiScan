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
use FacturaScripts\Dinamic\Model\Divisa;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use PHPUnit\Framework\TestCase;

/**
 * Issue #109: al importar una factura en moneda extranjera se debe usar el
 * factor de conversión de la tabla de divisas en lugar de dejarlo a 1.
 */
final class InvoiceMapperCurrencyTest extends TestCase
{
    private const CURRENCY = 'ZZP';

    /** @var FacturaProveedor[] */
    private array $invoicesToDelete = [];

    private ?Proveedor $supplier = null;

    private ?Divisa $currency = null;

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

        if ($this->supplier instanceof Proveedor && $this->supplier->exists()) {
            $address = $this->supplier->getDefaultAddress();
            if ($address->exists()) {
                $address->delete();
            }
            $this->supplier->delete();
        }

        if ($this->currency instanceof Divisa && $this->currency->exists()) {
            $this->currency->delete();
        }

        MiniLog::clear();
    }

    public function testForeignCurrencyUsesPurchaseConversionRate(): void
    {
        $this->createCurrency(4.3215, 4.25);

        $invoice = $this->importInvoice(strtolower(self::CURRENCY));

        $this->assertSame(self::CURRENCY, $invoice->coddivisa);
        $this->assertEqualsWithDelta(4.25, (float) $invoice->tasaconv, 0.00001);
    }

    public function testForeignCurrencyFallsBackToGeneralConversionRate(): void
    {
        $this->createCurrency(4.3215, null);

        $invoice = $this->importInvoice(self::CURRENCY);

        $this->assertSame(self::CURRENCY, $invoice->coddivisa);
        $this->assertEqualsWithDelta(4.3215, (float) $invoice->tasaconv, 0.00001);
    }

    private function importInvoice(string $currency): FacturaProveedor
    {
        $mapper = new InvoiceMapper();
        $result = $mapper->mapToInvoice([
            'invoice' => [
                'number' => 'CUR-' . mt_rand(10000, 99999),
                'issue_date' => '2026-06-01',
                'currency' => $currency,
            ],
            'supplier' => [
                'matched_supplier_id' => $this->createSupplier()->codproveedor,
                'match_status' => 'matched',
            ],
            'lines' => [[
                'description' => 'Producto polaco',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
            ]],
        ], null, 'lines', false);

        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));

        $invoice = new FacturaProveedor();
        $this->assertTrue($invoice->loadFromCode($result['invoice_id']), 'No se pudo recargar la factura');
        $this->invoicesToDelete[] = $invoice;

        return $invoice;
    }

    private function createCurrency(float $rate, ?float $purchaseRate): void
    {
        $currency = new Divisa();
        if (!$currency->load(self::CURRENCY)) {
            $currency->coddivisa = self::CURRENCY;
        }
        $currency->descripcion = 'Divisa AiScan test';
        $currency->simbolo = 'zł';
        $currency->tasaconv = $rate;
        $currency->tasaconvcompra = $purchaseRate;
        $this->assertTrue($currency->save(), 'No se pudo crear la divisa de prueba');
        $this->currency = $currency;
    }

    private function createSupplier(): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = 'AiScan Divisa ' . mt_rand(10000, 99999);
        $supplier->razonsocial = $supplier->nombre;
        $supplier->cifnif = 'Z' . mt_rand(10000000, 99999999);
        $supplier->personafisica = false;

        $series = (new Serie())->all([], [], 0, 1);
        if (!empty($series)) {
            $supplier->codserie = $series[0]->codserie;
        }

        $this->assertTrue($supplier->save(), 'No se pudo crear el proveedor de prueba');
        $this->supplier = $supplier;

        return $supplier;
    }
}
