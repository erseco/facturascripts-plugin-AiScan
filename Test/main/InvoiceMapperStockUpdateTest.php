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
use FacturaScripts\Core\Lib\FiscalNumberValidator as CoreFiscalNumberValidator;
use FacturaScripts\Core\Lib\RegimenIVA as CoreRegimenIVA;
use FacturaScripts\Core\Model\Contacto as CoreContacto;
use FacturaScripts\Core\Model\CuentaBancoProveedor as CoreCuentaBancoProveedor;
use FacturaScripts\Core\Model\CuentaEspecial as CoreCuentaEspecial;
use FacturaScripts\Core\Model\EstadoDocumento as CoreEstadoDocumento;
use FacturaScripts\Core\Model\FacturaProveedor;
use FacturaScripts\Core\Model\IdentificadorFiscal as CoreIdentificadorFiscal;
use FacturaScripts\Core\Model\Impuesto as CoreImpuesto;
use FacturaScripts\Core\Model\LineaFacturaProveedor as CoreLineaFacturaProveedor;
use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Model\ProductoProveedor;
use FacturaScripts\Core\Model\Proveedor;
use FacturaScripts\Core\Model\Retencion as CoreRetencion;
use FacturaScripts\Core\Model\Stock;
use FacturaScripts\Core\Model\Subcuenta as CoreSubcuenta;
use FacturaScripts\Core\Model\Variante;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use PHPUnit\Framework\TestCase;

final class InvoiceMapperStockUpdateTest extends TestCase
{
    /** @var FacturaProveedor[] */
    private array $invoicesToDelete = [];

    /** @var Producto[] */
    private array $productsToDelete = [];

    /** @var Proveedor[] */
    private array $suppliersToDelete = [];

    public static function setUpBeforeClass(): void
    {
        self::aliasDynamicClass(
            CoreFiscalNumberValidator::class,
            'FacturaScripts\\Dinamic\\Lib\\FiscalNumberValidator'
        );
        self::aliasDynamicClass(
            CoreIdentificadorFiscal::class,
            'FacturaScripts\\Dinamic\\Model\\IdentificadorFiscal'
        );
        self::aliasDynamicClass(CoreImpuesto::class, 'FacturaScripts\\Dinamic\\Model\\Impuesto');
        self::aliasDynamicClass(CoreRegimenIVA::class, 'FacturaScripts\\Dinamic\\Lib\\RegimenIVA');
        self::aliasDynamicClass(CoreContacto::class, 'FacturaScripts\\Dinamic\\Model\\Contacto');
        self::aliasDynamicClass(
            CoreCuentaBancoProveedor::class,
            'FacturaScripts\\Dinamic\\Model\\CuentaBancoProveedor'
        );
        self::aliasDynamicClass(CoreCuentaEspecial::class, 'FacturaScripts\\Dinamic\\Model\\CuentaEspecial');
        self::aliasDynamicClass(CoreEstadoDocumento::class, 'FacturaScripts\\Dinamic\\Model\\EstadoDocumento');
        self::aliasDynamicClass(FacturaProveedor::class, 'FacturaScripts\\Dinamic\\Model\\FacturaProveedor');
        self::aliasDynamicClass(
            CoreLineaFacturaProveedor::class,
            'FacturaScripts\\Dinamic\\Model\\LineaFacturaProveedor'
        );
        self::aliasDynamicClass(Producto::class, 'FacturaScripts\\Dinamic\\Model\\Producto');
        self::aliasDynamicClass(ProductoProveedor::class, 'FacturaScripts\\Dinamic\\Model\\ProductoProveedor');
        self::aliasDynamicClass(Proveedor::class, 'FacturaScripts\\Dinamic\\Model\\Proveedor');
        self::aliasDynamicClass(CoreRetencion::class, 'FacturaScripts\\Dinamic\\Model\\Retencion');
        self::aliasDynamicClass(Stock::class, 'FacturaScripts\\Dinamic\\Model\\Stock');
        self::aliasDynamicClass(CoreSubcuenta::class, 'FacturaScripts\\Dinamic\\Model\\Subcuenta');
        self::aliasDynamicClass(Variante::class, 'FacturaScripts\\Dinamic\\Model\\Variante');
    }

    protected function setUp(): void
    {
        Tools::settingsSet('default', 'updatesupplierprices', false);
        Tools::settingsSave();
    }

    private static function aliasDynamicClass(string $originalClass, string $dynamicClass): void
    {
        if (!class_exists($dynamicClass) && class_exists($originalClass)) {
            class_alias($originalClass, $dynamicClass);
        }
    }

    public function testMapToInvoiceKeepsBehaviorWhenStockUpdateDisabled(): void
    {
        [$supplier, $product] = $this->createSupplierAndProduct();
        $result = $this->mapInvoice($supplier->codproveedor, [[
            'referencia' => $product->referencia,
            'description' => 'Producto controlado',
            'quantity' => 3,
            'unit_price' => 12.5,
            'tax_rate' => 21,
        ]], false);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(0.0, $this->getStockQuantity($product->referencia));
        $this->assertNull($this->findSupplierProduct($supplier->codproveedor, $product->referencia));
        $this->assertSame(0, (int) $this->getImportedInvoice($result['invoice_id'])->getLines()[0]->actualizastock);
    }

    public function testMapToInvoiceAddsStockAndUpdatesPurchaseDataWhenEnabled(): void
    {
        [$supplier, $product] = $this->createSupplierAndProduct();
        $result = $this->mapInvoice($supplier->codproveedor, [[
            'referencia' => $product->referencia,
            'description' => 'Producto controlado',
            'quantity' => 3,
            'unit_price' => 12.5,
            'tax_rate' => 21,
        ]], true);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(3.0, $this->getStockQuantity($product->referencia));

        $supplierProduct = $this->findSupplierProduct($supplier->codproveedor, $product->referencia);
        $this->assertNotNull($supplierProduct);
        $this->assertEqualsWithDelta(12.5, $supplierProduct->precio, 0.0001);
    }

    public function testMapToInvoiceSkipsStockForNonStockControlledProduct(): void
    {
        [$supplier, $product] = $this->createSupplierAndProduct(true);
        $result = $this->mapInvoice($supplier->codproveedor, [[
            'referencia' => $product->referencia,
            'description' => 'Servicio sin stock',
            'quantity' => 2,
            'unit_price' => 14,
            'tax_rate' => 21,
        ]], true);

        $this->assertTrue($result['success']);
        $this->assertContainsTranslation('aiscan-stock-line-skipped-not-controlled', $result['warnings'], '1');
        $this->assertSame(0.0, $this->getStockQuantity($product->referencia));

        $supplierProduct = $this->findSupplierProduct($supplier->codproveedor, $product->referencia);
        $this->assertNotNull($supplierProduct);
        $this->assertEqualsWithDelta(14.0, $supplierProduct->precio, 0.0001);
    }

    public function testMapToInvoiceSkipsUnmatchedProductSafely(): void
    {
        $supplier = $this->createSupplier();
        $result = $this->mapInvoice($supplier->codproveedor, [[
            'description' => 'Producto sin coincidencia ' . mt_rand(1000, 9999),
            'quantity' => 2,
            'unit_price' => 10,
            'tax_rate' => 21,
        ]], true);

        $this->assertTrue($result['success']);
        $this->assertContainsTranslation('aiscan-stock-line-skipped-no-product', $result['warnings'], '1');
        $this->assertCount(1, $this->getImportedInvoice($result['invoice_id'])->getLines());
    }

    public function testMapToInvoiceIgnoresInvalidPriceForPurchaseData(): void
    {
        [$supplier, $product] = $this->createSupplierAndProduct();
        $result = $this->mapInvoice($supplier->codproveedor, [[
            'referencia' => $product->referencia,
            'description' => 'Producto controlado',
            'quantity' => 4,
            'unit_price' => 0,
            'tax_rate' => 21,
        ]], true);

        $this->assertTrue($result['success']);
        $this->assertContainsTranslation('aiscan-purchase-data-line-skipped-invalid-price', $result['warnings'], '1');
        $this->assertSame(4.0, $this->getStockQuantity($product->referencia));
        $this->assertNull($this->findSupplierProduct($supplier->codproveedor, $product->referencia));
    }

    public function testMapToInvoiceIgnoresInvalidQuantityForStockUpdate(): void
    {
        [$supplier, $product] = $this->createSupplierAndProduct();
        $result = $this->mapInvoice($supplier->codproveedor, [[
            'referencia' => $product->referencia,
            'description' => 'Producto controlado',
            'quantity' => 0,
            'unit_price' => 9.5,
            'tax_rate' => 21,
        ]], true);

        $this->assertTrue($result['success']);
        $this->assertContainsTranslation('aiscan-stock-line-skipped-invalid-quantity', $result['warnings'], '1');
        $this->assertSame(0.0, $this->getStockQuantity($product->referencia));

        $supplierProduct = $this->findSupplierProduct($supplier->codproveedor, $product->referencia);
        $this->assertNotNull($supplierProduct);
        $this->assertEqualsWithDelta(9.5, $supplierProduct->precio, 0.0001);
    }

    public function testMapToInvoiceUpdatesEachEligibleLineIndependently(): void
    {
        [$supplier, $firstProduct] = $this->createSupplierAndProduct();
        $secondProduct = $this->createProduct();

        $result = $this->mapInvoice($supplier->codproveedor, [
            [
                'referencia' => $firstProduct->referencia,
                'description' => 'Producto A',
                'quantity' => 2,
                'unit_price' => 5,
                'tax_rate' => 21,
            ],
            [
                'referencia' => $secondProduct->referencia,
                'description' => 'Producto B',
                'quantity' => 3,
                'unit_price' => 7,
                'tax_rate' => 21,
            ],
        ], true);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(2.0, $this->getStockQuantity($firstProduct->referencia));
        $this->assertSame(3.0, $this->getStockQuantity($secondProduct->referencia));
        $this->assertNotNull($this->findSupplierProduct($supplier->codproveedor, $firstProduct->referencia));
        $this->assertNotNull($this->findSupplierProduct($supplier->codproveedor, $secondProduct->referencia));
    }

    protected function tearDown(): void
    {
        foreach ($this->invoicesToDelete as $invoice) {
            if ($invoice->exists()) {
                $invoice->delete();
            }
        }

        foreach ($this->suppliersToDelete as $supplier) {
            if ($supplier->exists()) {
                $supplier->getDefaultAddress()->delete();
                $supplier->delete();
            }
        }

        foreach ($this->productsToDelete as $product) {
            if ($product->exists()) {
                $product->delete();
            }
        }

        $this->logErrors();
    }

    /**
     * @return array{0: Proveedor, 1: Producto}
     */
    private function createSupplierAndProduct(bool $noStock = false): array
    {
        return [$this->createSupplier(), $this->createProduct($noStock)];
    }

    private function createSupplier(): Proveedor
    {
        $supplier = $this->getRandomSupplier('AiScan stock test');
        $this->assertTrue($supplier->save(), 'supplier-save-failed');
        $this->suppliersToDelete[] = $supplier;

        return $supplier;
    }

    private function createProduct(bool $noStock = false): Producto
    {
        $product = $this->getRandomProduct();
        $product->nostock = $noStock;
        $this->assertTrue($product->save(), 'product-save-failed');
        $this->productsToDelete[] = $product;

        return $product;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function mapInvoice(string $supplierCode, array $lines, bool $updateStockPurchaseData): array
    {
        $mapper = new InvoiceMapper();
        $result = $mapper->mapToInvoice([
            'invoice' => [
                'number' => 'AI-' . mt_rand(10000, 99999),
                'issue_date' => '2026-05-20',
                'currency' => 'EUR',
                'summary' => 'AiScan stock update test',
            ],
            'supplier' => [
                'matched_supplier_id' => $supplierCode,
                'match_status' => 'matched',
            ],
            'lines' => $lines,
        ], null, 'lines', $updateStockPurchaseData);

        if (!empty($result['invoice_id'])) {
            $this->invoicesToDelete[] = $this->getImportedInvoice($result['invoice_id']);
        }

        return $result;
    }

    private function getImportedInvoice(int $invoiceId): FacturaProveedor
    {
        $invoice = new FacturaProveedor();
        $this->assertTrue($invoice->loadFromCode($invoiceId), 'invoice-load-failed');

        return $invoice;
    }

    private function getStockQuantity(string $reference): float
    {
        $variant = new Variante();
        $this->assertTrue($variant->loadWhere([Where::eq('referencia', $reference)]), 'variant-load-failed');

        $stock = new Stock();
        $where = [Where::eq('referencia', $reference)];
        if (false === $stock->loadWhere($where)) {
            return 0.0;
        }

        return (float) $stock->cantidad;
    }

    private function findSupplierProduct(string $supplierCode, string $reference): ?ProductoProveedor
    {
        $product = new ProductoProveedor();
        $where = [
            Where::eq('codproveedor', $supplierCode),
            Where::eq('referencia', $reference),
        ];

        return $product->loadWhere($where) ? $product : null;
    }

    /**
     * @param array<int, string> $warnings
     */
    private function assertContainsTranslation(string $key, array $warnings, string $line): void
    {
        $message = Tools::lang()->trans($key, ['%line%' => $line]);
        $this->assertContains($message, $warnings);
    }

    private function getRandomSupplier(string $testName = ''): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->cifnif = mt_rand(1, 99999999) . 'J';
        $supplier->nombre = 'Proveedor Rand ' . mt_rand(1, 999);
        $supplier->observaciones = $testName;
        $supplier->razonsocial = 'Empresa ' . mt_rand(1, 999);

        return $supplier;
    }

    private function getRandomProduct(): Producto
    {
        $num = mt_rand(1, 99999);
        $product = new Producto();
        $product->referencia = 'test' . $num;
        $product->descripcion = 'Test Product ' . $num;

        return $product;
    }

    protected function logErrors(bool $force = false): void
    {
        if ($this->getStatus() > 1 || $force) {
            foreach (MiniLog::read('', ['critical', 'error', 'warning']) as $item) {
                error_log($item['message']);
                if (!empty($item['context'])) {
                    error_log(print_r($item['context'], true));
                }
            }

            $queries = [];
            foreach (MiniLog::read('database') as $item) {
                $queries[] = $item['message'];
            }

            $filePath = Tools::folder(
                'MyFiles',
                'test_error_' . date('Y-m-d_H-i-s_') . random_int(0, 1000000) . '.log'
            );
            file_put_contents($filePath, implode(PHP_EOL, $queries) . PHP_EOL, FILE_APPEND);
            error_log('Database queries in ' . $filePath . PHP_EOL);

            foreach (array_slice($queries, -5) as $query) {
                error_log($query);
            }
        }

        MiniLog::clear();
    }
}
