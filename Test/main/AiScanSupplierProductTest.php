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

use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Plugins\AiScan\Lib\HistoricalContextService;
use FacturaScripts\Plugins\AiScan\Model\AiScanSupplierProduct;
use PHPUnit\Framework\TestCase;

/**
 * Issue #69: el producto por defecto del proveedor debe persistir y recordarse
 * en facturas posteriores del mismo codproveedor.
 */
final class AiScanSupplierProductTest extends TestCase
{
    /** @var Proveedor[] */
    private array $suppliersToDelete = [];

    /** @var string[] */
    private array $codproveedoresToClear = [];

    protected function tearDown(): void
    {
        foreach ($this->codproveedoresToClear as $cod) {
            $existing = AiScanSupplierProduct::getForSupplier($cod);
            if ($existing) {
                $existing->delete();
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
    }

    /**
     * @testdox setForSupplier + getForSupplier persisten y recuperan el producto fijado (#69)
     */
    public function testPinPersistsAcrossLookups(): void
    {
        $supplier = $this->createSupplier();
        $ref = $this->anyProductReference();
        if ($ref === null) {
            $this->markTestSkipped('No hay productos en la BD de prueba.');
        }

        $this->codproveedoresToClear[] = $supplier->codproveedor;

        $this->assertTrue(
            AiScanSupplierProduct::setForSupplier($supplier->codproveedor, $ref, 'Producto habitual'),
            'setForSupplier debe guardar sin error'
        );

        $found = AiScanSupplierProduct::getForSupplier($supplier->codproveedor);
        $this->assertNotNull($found, 'getForSupplier debe devolver la fila guardada');
        $this->assertSame($ref, $found->referencia);
        $this->assertSame('Producto habitual', $found->description);

        // Segunda lectura (simula la siguiente factura del mismo proveedor)
        $again = AiScanSupplierProduct::getForSupplier($supplier->codproveedor);
        $this->assertNotNull($again);
        $this->assertSame($ref, $again->referencia);
    }

    /**
     * @testdox setForSupplier actualiza la referencia si ya existía un pin (#69)
     */
    public function testPinCanBeUpdated(): void
    {
        $supplier = $this->createSupplier();
        $ref = $this->anyProductReference();
        if ($ref === null) {
            $this->markTestSkipped('No hay productos en la BD de prueba.');
        }

        $this->codproveedoresToClear[] = $supplier->codproveedor;

        $this->assertTrue(AiScanSupplierProduct::setForSupplier($supplier->codproveedor, $ref, 'v1'));
        $this->assertTrue(AiScanSupplierProduct::setForSupplier($supplier->codproveedor, $ref, 'v2'));

        $found = AiScanSupplierProduct::getForSupplier($supplier->codproveedor);
        $this->assertNotNull($found);
        $this->assertSame('v2', $found->description);
        $this->assertSame(1, count(AiScanSupplierProduct::all(
            [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('codproveedor', $supplier->codproveedor)],
            [],
            0,
            10
        )));
    }

    /**
     * @testdox getSuggestedProduct prioriza el producto fijado (pinned) sobre el histórico (#69)
     */
    public function testSuggestedProductPrefersPinned(): void
    {
        $supplier = $this->createSupplier();
        $ref = $this->anyProductReference();
        if ($ref === null) {
            $this->markTestSkipped('No hay productos en la BD de prueba.');
        }

        $this->codproveedoresToClear[] = $supplier->codproveedor;
        $this->assertTrue(AiScanSupplierProduct::setForSupplier($supplier->codproveedor, $ref, 'Fijado'));

        $service = new HistoricalContextService();
        $suggestion = $service->getSuggestedProduct($supplier->codproveedor);

        $this->assertNotNull($suggestion);
        $this->assertSame($ref, $suggestion['referencia']);
        $this->assertSame('pinned', $suggestion['source']);
    }

    /**
     * @testdox getForSupplier con codproveedor desconocido devuelve null (#69)
     */
    public function testUnknownSupplierReturnsNull(): void
    {
        $this->assertNull(AiScanSupplierProduct::getForSupplier('NOEXISTE99'));
    }

    private function createSupplier(): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = 'AiScan DefaultProd ' . mt_rand(10000, 99999);
        $supplier->razonsocial = $supplier->nombre;
        $supplier->cifnif = 'B' . mt_rand(10000000, 99999999);
        $supplier->personafisica = false;
        $this->assertTrue($supplier->save());
        $this->suppliersToDelete[] = $supplier;

        return $supplier;
    }

    private function anyProductReference(): ?string
    {
        $variant = new Variante();
        foreach ($variant->all([], [], 0, 1) as $row) {
            $ref = trim((string) $row->referencia);
            if ($ref !== '') {
                return $ref;
            }
        }

        return null;
    }
}
