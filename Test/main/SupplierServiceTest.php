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

use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\AiScan\Lib\SupplierService;
use PHPUnit\Framework\TestCase;

/**
 * Cubre la resolución de terceros como proveedor o acreedor.
 *
 * En FacturaScripts ambos comparten el modelo Proveedor; el flag
 * `acreedor` decide la subcuenta contable especial (PROVEE vs ACREED).
 */
final class SupplierServiceTest extends TestCase
{
    private SupplierService $service;

    /** @var Proveedor[] */
    private array $suppliersToDelete = [];

    protected function setUp(): void
    {
        $this->service = new SupplierService();
    }

    protected function tearDown(): void
    {
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
     * @testdox Por defecto resolveIsCreditor devuelve false (proveedor)
     */
    public function testPorDefectoNoEsAcreedor(): void
    {
        $this->assertFalse($this->service->resolveIsCreditor([]));
        $this->assertFalse($this->service->resolveIsCreditor(['party_type' => 'supplier']));
        $this->assertFalse($this->service->resolveIsCreditor(['party_type' => 'proveedor']));
    }

    /**
     * @testdox party_type creditor o acreedor se interpreta como acreedor
     */
    public function testPartyTypeAcreedorSeReconoce(): void
    {
        $this->assertTrue($this->service->resolveIsCreditor(['party_type' => 'creditor']));
        $this->assertTrue($this->service->resolveIsCreditor(['party_type' => 'acreedor']));
        $this->assertTrue($this->service->resolveIsCreditor(['party_type' => 'CREDITOR']));
    }

    /**
     * @testdox is_creditor acepta booleanos, enteros y strings comunes
     */
    public function testIsCreditorAceptaVariosFormatos(): void
    {
        $this->assertTrue($this->service->resolveIsCreditor(['is_creditor' => true]));
        $this->assertTrue($this->service->resolveIsCreditor(['is_creditor' => 1]));
        $this->assertTrue($this->service->resolveIsCreditor(['is_creditor' => '1']));
        $this->assertTrue($this->service->resolveIsCreditor(['is_creditor' => 'true']));
        $this->assertTrue($this->service->resolveIsCreditor(['is_creditor' => 'sí']));
        $this->assertFalse($this->service->resolveIsCreditor(['is_creditor' => false]));
        $this->assertFalse($this->service->resolveIsCreditor(['is_creditor' => 0]));
        $this->assertFalse($this->service->resolveIsCreditor(['is_creditor' => 'no']));
    }

    /**
     * @testdox is_creditor tiene prioridad sobre party_type
     */
    public function testIsCreditorTienePrioridadSobrePartyType(): void
    {
        $this->assertFalse($this->service->resolveIsCreditor([
            'party_type' => 'creditor',
            'is_creditor' => false,
        ]));
        $this->assertTrue($this->service->resolveIsCreditor([
            'party_type' => 'supplier',
            'is_creditor' => true,
        ]));
    }

    /**
     * @testdox Al crear un tercero nuevo como acreedor se marca Proveedor.acreedor
     */
    public function testCrearNuevoComoAcreedor(): void
    {
        $name = 'AiScan Acreedor ' . mt_rand(10000, 99999);
        $data = [
            'name' => $name,
            'tax_id' => 'B' . mt_rand(10000000, 99999999),
            'create_if_missing' => true,
            'party_type' => SupplierService::PARTY_CREDITOR,
        ];

        $supplier = $this->service->resolve($data);
        $this->assertInstanceOf(Proveedor::class, $supplier);
        $this->suppliersToDelete[] = $supplier;

        $this->assertTrue((bool) $supplier->acreedor, 'El proveedor debe quedar marcado como acreedor');
        $this->assertSame(SupplierService::PARTY_CREDITOR, $data['party_type']);
        $this->assertTrue($data['is_creditor']);
        $this->assertSame('created', $data['match_status']);
        $this->assertNotEmpty($data['matched_supplier_id']);
    }

    /**
     * @testdox Al crear un tercero nuevo como proveedor el flag acreedor queda a false
     */
    public function testCrearNuevoComoProveedor(): void
    {
        $name = 'AiScan Proveedor ' . mt_rand(10000, 99999);
        $data = [
            'name' => $name,
            'tax_id' => 'A' . mt_rand(10000000, 99999999),
            'create_if_missing' => true,
            'party_type' => SupplierService::PARTY_SUPPLIER,
        ];

        $supplier = $this->service->resolve($data);
        $this->assertInstanceOf(Proveedor::class, $supplier);
        $this->suppliersToDelete[] = $supplier;

        $this->assertFalse((bool) $supplier->acreedor, 'El proveedor no debe quedar marcado como acreedor');
        $this->assertSame(SupplierService::PARTY_SUPPLIER, $data['party_type']);
        $this->assertFalse($data['is_creditor']);
    }

    /**
     * @testdox Si se reutiliza un proveedor existente y se pide acreedor, se actualiza el flag
     */
    public function testActualizaFlagAcreedorEnProveedorExistente(): void
    {
        $existing = $this->createBareSupplier(false);
        $data = [
            'matched_supplier_id' => $existing->codproveedor,
            'party_type' => SupplierService::PARTY_CREDITOR,
        ];

        $resolved = $this->service->resolve($data);
        $this->assertInstanceOf(Proveedor::class, $resolved);
        $this->assertSame($existing->codproveedor, $resolved->codproveedor);

        $reloaded = new Proveedor();
        $this->assertTrue($reloaded->loadFromCode($existing->codproveedor));
        $this->assertTrue((bool) $reloaded->acreedor, 'El flag acreedor debe actualizarse en el registro existente');
    }

    /**
     * @testdox Sin party_type ni is_creditor no se modifica el flag de un proveedor existente
     */
    public function testNoModificaFlagSiNoSeIndicaTipo(): void
    {
        $existing = $this->createBareSupplier(true);
        $data = [
            'matched_supplier_id' => $existing->codproveedor,
        ];

        $resolved = $this->service->resolve($data);
        $this->assertInstanceOf(Proveedor::class, $resolved);

        $reloaded = new Proveedor();
        $this->assertTrue($reloaded->loadFromCode($existing->codproveedor));
        $this->assertTrue((bool) $reloaded->acreedor, 'Sin tipo explícito debe preservarse el valor actual');
        $this->assertTrue($data['is_creditor']);
        $this->assertSame(SupplierService::PARTY_CREDITOR, $data['party_type']);
    }

    private function createBareSupplier(bool $isCreditor): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = 'AiScan Tipo ' . mt_rand(10000, 99999);
        $supplier->razonsocial = $supplier->nombre;
        $supplier->cifnif = 'X' . mt_rand(10000000, 99999999);
        $supplier->personafisica = false;
        $supplier->acreedor = $isCreditor;

        if (empty($supplier->codpago)) {
            $paymentMethods = (new FormaPago())->all([], [], 0, 1);
            if (!empty($paymentMethods)) {
                $supplier->codpago = $paymentMethods[0]->codpago;
            }
        }
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
