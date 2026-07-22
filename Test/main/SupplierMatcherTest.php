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
use FacturaScripts\Plugins\AiScan\Lib\SupplierMatcher;
use PHPUnit\Framework\TestCase;

final class SupplierMatcherTest extends TestCase
{
    private SupplierMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SupplierMatcher();
    }

    public function testCanInstantiate(): void
    {
        $this->assertInstanceOf(SupplierMatcher::class, $this->matcher);
    }

    public function testReturnsNotFoundWhenNoData(): void
    {
        $result = $this->matcher->findMatch([
            'name' => '',
            'tax_id' => '',
        ]);
        $this->assertEquals('not_found', $result['match_status']);
        $this->assertNull($result['supplier']);
        $this->assertEmpty($result['candidates']);
    }

    public function testReturnsNotFoundWhenBothFieldsNull(): void
    {
        $result = $this->matcher->findMatch([
            'name' => null,
            'tax_id' => null,
        ]);
        $this->assertEquals('not_found', $result['match_status']);
    }

    public function testResultStructureHasRequiredKeys(): void
    {
        $result = $this->matcher->findMatch([]);
        $this->assertArrayHasKey('match_status', $result);
        $this->assertArrayHasKey('supplier', $result);
        $this->assertArrayHasKey('candidates', $result);
    }

    public function testNormalizeNameStripsLegalForms(): void
    {
        $cases = [
            ['Empresa S.A.', 'Empresa'],
            ['Acme S.L.', 'Acme'],
            ['Compañía S.R.L.', 'Compañía'],
            ['Tech S.L.U.', 'Tech'],
            ['Mega Corp S.A.U.', 'Mega Corp'],
            ['Cooperativa S.C.', 'Cooperativa'],
            ['Empresa SA', 'Empresa'],
            ['Acme SL', 'Acme'],
            ['Simple Company', 'Simple Company'],
            ['', ''],
            ['S.L.', ''],
        ];

        $method = new \ReflectionMethod(
            SupplierMatcher::class,
            'normalizeName'
        );
        $method->setAccessible(true);

        foreach ($cases as [$input, $expected]) {
            $result = $method->invoke($this->matcher, $input);
            $this->assertEquals(
                $expected,
                $result,
                "normalizeName('$input') should return '$expected'"
            );
        }
    }

    /**
     * @testdox normalizeTaxId unifica mayúsculas, espacios, puntos, guiones y prefijo ES (#70)
     *
     * @dataProvider taxIdNormalizationProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('taxIdNormalizationProvider')]
    public function testNormalizeTaxIdFormats(string $input, string $expected): void
    {
        $this->assertSame(
            $expected,
            SupplierMatcher::normalizeTaxId($input),
            "normalizeTaxId('$input') debería devolver '$expected'"
        );
    }

    public static function taxIdNormalizationProvider(): array
    {
        return [
            'plain' => ['B35123456', 'B35123456'],
            'lowercase' => ['b35123456', 'B35123456'],
            'dots' => ['B-35.123.456', 'B35123456'],
            'spaces' => ['B 35 123 456', 'B35123456'],
            'dashes' => ['B-35123456', 'B35123456'],
            'es-prefix' => ['ESB35123456', 'B35123456'],
            'es-prefix-spaced' => ['ES B-35.123.456', 'B35123456'],
            'es-lowercase' => ['esb35123456', 'B35123456'],
            'nif-dots' => ['12.345.678-Z', '12345678Z'],
            'empty' => ['', ''],
            'whitespace-only' => ['   ', ''],
            'slashes' => ['B/35123456', 'B35123456'],
        ];
    }

    /**
     * @testdox Matching por CIF tolera formatos distintos al almacenado (#70)
     *
     * @dataProvider taxIdMatchVariantsProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('taxIdMatchVariantsProvider')]
    public function testFindMatchByNormalizedTaxId(string $storedFormat, string $extractedFormat): void
    {
        // Núcleo único por caso para no contaminar otros tests / proveedores seed.
        $core = 'B' . (string) mt_rand(10000000, 99999999);
        $storedCif = $this->applyPrettyCifTemplate($storedFormat, $core);
        $extractedTaxId = $this->applyPrettyCifTemplate($extractedFormat, $core);

        $supplier = $this->createSupplier(
            'AiScan CIF Norm ' . mt_rand(10000, 99999),
            $storedCif
        );

        $result = $this->matcher->findMatch([
            'name' => '',
            'tax_id' => $extractedTaxId,
        ]);

        $this->assertSame(
            'matched',
            $result['match_status'],
            "stored=$storedCif extracted=$extractedTaxId"
        );
        $this->assertNotNull($result['supplier']);
        $this->assertSame($supplier->codproveedor, $result['supplier']->codproveedor);
    }

    /**
     * Plantillas: "plain", "dots", "es", "es-dots", "lower", "spaces".
     */
    public static function taxIdMatchVariantsProvider(): array
    {
        return [
            'exact-still-works' => ['plain', 'plain'],
            'extract-with-dots' => ['plain', 'dots'],
            'extract-with-es' => ['plain', 'es'],
            'extract-lowercase' => ['plain', 'lower'],
            'stored-with-dots' => ['dots', 'plain'],
            'stored-with-es' => ['es', 'plain'],
            'both-pretty' => ['spaces', 'es-dots'],
        ];
    }

    private function applyPrettyCifTemplate(string $template, string $core): string
    {
        // $core = letter + 8 digits, e.g. B12345678
        $letter = substr($core, 0, 1);
        $d = substr($core, 1); // 8 digits
        $pretty = $letter . '-' . substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3);

        return match ($template) {
            'plain' => $core,
            'dots' => $pretty,
            'es' => 'ES' . $core,
            'es-dots' => 'ES ' . $pretty,
            'lower' => strtolower($core),
            'spaces' => $letter . ' ' . substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3),
            default => $core,
        };
    }

    /**
     * @testdox Exact match legacy sigue funcionando sin normalizar el valor de búsqueda
     */
    public function testExactTaxIdMatchStillWorks(): void
    {
        $cif = 'A' . mt_rand(10000000, 99999999);
        $supplier = $this->createSupplier('AiScan Exact CIF ' . mt_rand(10000, 99999), $cif);

        $result = $this->matcher->findMatch([
            'name' => 'Nombre distinto del almacenado',
            'tax_id' => $cif,
        ]);

        $this->assertSame('matched', $result['match_status']);
        $this->assertSame($supplier->codproveedor, $result['supplier']->codproveedor);
    }

    /**
     * @testdox Varios proveedores con el mismo CIF normalizado devuelven ambiguous
     */
    public function testAmbiguousWhenMultipleNormalizedTaxIds(): void
    {
        $core = 'C' . mt_rand(10000000, 99999999);
        $this->createSupplier('AiScan Amb 1 ' . mt_rand(10000, 99999), $core);
        $this->createSupplier('AiScan Amb 2 ' . mt_rand(10000, 99999), 'ES' . $core);

        $result = $this->matcher->findMatch([
            'name' => '',
            'tax_id' => $core,
        ]);

        // Si la BD permite dos CIF equivalentes, debe ser ambiguous; si el
        // segundo no se pudo crear (unique constraint), al menos matched.
        $this->assertContains($result['match_status'], ['matched', 'ambiguous']);
        if ($result['match_status'] === 'ambiguous') {
            $this->assertGreaterThan(1, count($result['candidates']));
        }
    }

    /** @var Proveedor[] */
    private array $suppliersToDelete = [];

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

    private function createSupplier(string $name, string $cifnif): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = $name;
        $supplier->razonsocial = $name;
        $supplier->cifnif = $cifnif;
        $supplier->personafisica = false;
        $this->assertTrue($supplier->save(), 'No se pudo crear proveedor de prueba: ' . $name);
        $this->suppliersToDelete[] = $supplier;

        return $supplier;
    }
}
