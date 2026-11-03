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

use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceEnricher;
use FacturaScripts\Plugins\AiScan\Model\AiScanSupplierProduct;
use PHPUnit\Framework\TestCase;

final class InvoiceEnricherTest extends TestCase
{
    /** @var Producto[] */
    private array $products = [];
    private ?Proveedor $supplier = null;

    protected function tearDown(): void
    {
        if ($this->supplier !== null) {
            AiScanSupplierProduct::clearForSupplier($this->supplier->codproveedor);
            $address = $this->supplier->getDefaultAddress();
            if ($address->exists()) {
                $address->delete();
            }
            $this->supplier->delete();
        }

        foreach ($this->products as $product) {
            $product->delete();
        }
    }

    /**
     * The supplier's usual product is a last resort: a line whose description
     * matches exactly one catalogue product must keep that product.
     */
    public function testUniqueDescriptionMatchWinsOverSupplierUsualProduct(): void
    {
        $usual = $this->createProduct('Enricher usual ');
        $exact = $this->createProduct('Enricher exact ');
        $supplier = $this->createSupplier();
        $this->assertTrue(AiScanSupplierProduct::setForSupplier(
            $supplier->codproveedor,
            $usual->referencia,
            $usual->descripcion
        ));

        $enriched = (new InvoiceEnricher())->enrich([
            'supplier' => ['name' => $supplier->nombre, 'tax_id' => $supplier->cifnif],
            'invoice' => ['number' => 'ENR-' . bin2hex(random_bytes(4))],
            'lines' => [
                ['description' => $exact->descripcion, 'quantity' => 1, 'unit_price' => 10],
                ['description' => 'Not in the catalogue ' . bin2hex(random_bytes(6)), 'quantity' => 1],
            ],
        ]);

        $this->assertSame($supplier->codproveedor, $enriched['supplier']['matched_supplier_id']);
        $this->assertSame($exact->referencia, $enriched['lines'][0]['referencia']);
        $this->assertNotSame('history', $enriched['lines'][0]['referencia_source'] ?? null);
        $this->assertSame($usual->referencia, $enriched['lines'][1]['referencia']);
        $this->assertSame('history', $enriched['lines'][1]['referencia_source']);
    }

    private function createProduct(string $descriptionPrefix): Producto
    {
        $product = new Producto();
        $product->referencia = 'ENR-' . bin2hex(random_bytes(4));
        $product->descripcion = $descriptionPrefix . bin2hex(random_bytes(6));
        $this->assertTrue($product->save());
        $this->products[] = $product;

        return $product;
    }

    private function createSupplier(): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = 'AiScan Enricher ' . mt_rand(10000, 99999);
        $supplier->razonsocial = $supplier->nombre;
        $supplier->cifnif = 'B' . mt_rand(10000000, 99999999);
        $supplier->personafisica = false;
        $this->assertTrue($supplier->save());
        $this->supplier = $supplier;

        return $supplier;
    }
}
