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

    /** @var Proveedor[] */
    private array $suppliers = [];

    protected function tearDown(): void
    {
        foreach ($this->suppliers as $supplier) {
            AiScanSupplierProduct::clearForSupplier($supplier->codproveedor);
            $address = $supplier->getDefaultAddress();
            if ($address->exists()) {
                $address->delete();
            }
            $supplier->delete();
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

    public function testPartialNameOffersTheSupplierAsCandidate(): void
    {
        $suffix = (string) mt_rand(10000, 99999);
        $stored = $this->createSupplier('Solar Enricher Canaria ' . $suffix);

        $enriched = (new InvoiceEnricher())->enrich([
            'supplier' => ['name' => 'Enricher Canaria ' . $suffix],
            'lines' => [],
        ]);

        $this->assertSame('ambiguous', $enriched['supplier']['match_status']);
        $this->assertArrayNotHasKey('matched_supplier_id', $enriched['supplier']);
        $this->assertSame(
            [['id' => $stored->codproveedor, 'name' => $stored->nombre, 'tax_id' => $stored->cifnif]],
            $enriched['supplier']['candidates']
        );
    }

    public function testSupplierWithoutUsualProductLeavesLinesAsExtracted(): void
    {
        $supplier = $this->createSupplier();
        $line = ['description' => 'No history ' . bin2hex(random_bytes(6)), 'quantity' => 1];

        $enriched = (new InvoiceEnricher())->enrich([
            'supplier' => ['name' => $supplier->nombre, 'tax_id' => $supplier->cifnif],
            'lines' => [$line],
        ]);

        $this->assertSame($supplier->codproveedor, $enriched['supplier']['matched_supplier_id']);
        $this->assertNull($enriched['_product_suggestion']);
        $this->assertSame([$line], $enriched['lines']);
    }

    /**
     * Issue #69: total mode has no lines, but the review still needs the
     * supplier's usual product.
     */
    public function testTotalModeKeepsTheUsualProductSuggestion(): void
    {
        $usual = $this->createProduct('Enricher total ');
        $supplier = $this->createSupplier();
        $this->assertTrue(AiScanSupplierProduct::setForSupplier($supplier->codproveedor, $usual->referencia));

        $enriched = (new InvoiceEnricher())->enrich([
            'supplier' => ['name' => $supplier->nombre, 'tax_id' => $supplier->cifnif],
        ]);

        $this->assertSame($usual->referencia, $enriched['_product_suggestion']['referencia']);
        $this->assertArrayNotHasKey('lines', $enriched);
    }

    public function testKnownReferenceIsKeptBarcodeResolvedAndInventedOneReplaced(): void
    {
        $usual = $this->createProduct('Enricher usual ');
        $known = $this->createProduct('Enricher known ');
        $barcoded = $this->createProduct('Enricher barcode ');
        $variant = $barcoded->getVariants()[0];
        $variant->codbarras = '84' . mt_rand(1000000000, 9999999999);
        $this->assertTrue($variant->save());
        $supplier = $this->createSupplier();
        $this->assertTrue(AiScanSupplierProduct::setForSupplier($supplier->codproveedor, $usual->referencia));

        $enriched = (new InvoiceEnricher())->enrich([
            'supplier' => ['name' => $supplier->nombre, 'tax_id' => $supplier->cifnif],
            'lines' => [
                ['referencia' => $known->referencia, 'description' => 'Line one ' . bin2hex(random_bytes(6))],
                ['sku' => $variant->codbarras, 'description' => 'Line two ' . bin2hex(random_bytes(6))],
                ['referencia' => 'AI-' . bin2hex(random_bytes(4)), 'sku' => 'AI-SKU',
                    'description' => 'Line three ' . bin2hex(random_bytes(6))],
            ],
        ]);

        $lines = $enriched['lines'];
        $this->assertSame($known->referencia, $lines[0]['referencia']);
        $this->assertArrayNotHasKey('referencia_source', $lines[0]);
        $this->assertSame($barcoded->referencia, $lines[1]['referencia']);
        $this->assertArrayNotHasKey('referencia_source', $lines[1]);
        $this->assertSame($usual->referencia, $lines[2]['referencia']);
        $this->assertSame('history', $lines[2]['referencia_source']);
        $this->assertArrayNotHasKey('sku', $lines[2]);
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

    private function createSupplier(?string $name = null): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = $name ?? 'AiScan Enricher ' . mt_rand(10000, 99999);
        $supplier->razonsocial = $supplier->nombre;
        $supplier->cifnif = 'B' . mt_rand(10000000, 99999999);
        $supplier->personafisica = false;
        $this->assertTrue($supplier->save());
        $this->suppliers[] = $supplier;

        return $supplier;
    }
}
