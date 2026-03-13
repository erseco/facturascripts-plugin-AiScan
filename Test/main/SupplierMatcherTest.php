<?php

/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Ernesto Serrano <ernesto@erseco.es>
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
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('legalFormProvider')]
    public function testNormalizeNameStripsLegalForms(
        string $input,
        string $expected
    ): void {
        $method = new \ReflectionMethod(SupplierMatcher::class, 'normalizeName');
        $method->setAccessible(true);
        $result = $method->invoke($this->matcher, $input);
        $this->assertEquals($expected, $result);
    }

    public static function legalFormProvider(): array
    {
        return [
            'S.A.' => ['Empresa S.A.', 'Empresa'],
            'S.L.' => ['Acme S.L.', 'Acme'],
            'S.R.L.' => ['Compañía S.R.L.', 'Compañía'],
            'S.L.U.' => ['Tech S.L.U.', 'Tech'],
            'S.A.U.' => ['Mega Corp S.A.U.', 'Mega Corp'],
            'S.C.' => ['Cooperativa S.C.', 'Cooperativa'],
            'without dots SA' => ['Empresa SA', 'Empresa'],
            'without dots SL' => ['Acme SL', 'Acme'],
            'no legal form' => ['Simple Company', 'Simple Company'],
            'empty string' => ['', ''],
            'only legal form' => ['S.L.', ''],
        ];
    }
}
