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
            ['Empresa S.A.', 'empresa'],
            ['Acme S.L.', 'acme'],
            ['Compañía S.R.L.', 'compania'],
            ['Tech S.L.U.', 'tech'],
            ['Mega Corp S.A.U.', 'mega corp'],
            ['Cooperativa S.C.', 'cooperativa'],
            ['Empresa SA', 'empresa'],
            ['Acme SL', 'acme'],
            ['Simple Company', 'simple company'],
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

    public function testMatchesSupplierByNormalizedTaxId(): void
    {
        $matcher = $this->createMatcher(
            taxCandidates: [
                $this->supplier('SUP-01', 'Acme Supplies S.L.', 'B12345678'),
            ]
        );

        $result = $matcher->findMatch([
            'name' => 'Acme Supplies SL',
            'tax_id' => 'ES-B12345678',
        ]);

        $this->assertSame('matched', $result['match_status']);
        $this->assertSame('SUP-01', $result['supplier']->codproveedor);
        $this->assertEmpty($result['candidates']);
    }

    public function testReturnsAmbiguousWhenNameIsTooBroad(): void
    {
        $matcher = $this->createMatcher(
            nameCandidates: [
                $this->supplier('SUP-01', 'Acme Tools S.L.', 'B11111111'),
                $this->supplier('SUP-02', 'Acme Services S.L.', 'B22222222'),
            ]
        );

        $result = $matcher->findMatch([
            'name' => 'Acme',
            'tax_id' => '',
        ]);

        $this->assertSame('ambiguous', $result['match_status']);
        $this->assertNull($result['supplier']);
        $this->assertCount(2, $result['candidates']);
    }

    public function testSearchRanksBestSupplierFirst(): void
    {
        $matcher = $this->createMatcher(
            nameCandidates: [
                $this->supplier('SUP-02', 'Acme Services S.L.', 'B22222222'),
                $this->supplier('SUP-01', 'Acme S.L.', 'B11111111'),
                $this->supplier('SUP-03', 'Other Supplier', 'B33333333'),
            ]
        );

        $results = $matcher->search('Acme SL');

        $this->assertCount(2, $results);
        $this->assertSame('SUP-01', $results[0]->codproveedor);
        $this->assertSame('SUP-02', $results[1]->codproveedor);
    }

    private function createMatcher(array $taxCandidates = [], array $nameCandidates = []): SupplierMatcher
    {
        return new class ($taxCandidates, $nameCandidates) extends SupplierMatcher {
            public function __construct(
                private array $taxCandidates,
                private array $nameCandidates
            ) {
            }

            protected function findSuppliersByTaxId(string $taxId, int $limit = 20): array
            {
                return $this->taxCandidates;
            }

            protected function findSuppliersByName(string $name, int $limit = 20): array
            {
                return $this->nameCandidates;
            }
        };
    }

    private function supplier(string $code, string $name, string $taxId): object
    {
        return (object) [
            'codproveedor' => $code,
            'nombre' => $name,
            'cifnif' => $taxId,
        ];
    }
}
