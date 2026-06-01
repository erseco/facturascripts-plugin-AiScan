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

use FacturaScripts\Plugins\AiScan\Lib\ProductMatcher;
use PHPUnit\Framework\TestCase;

final class ProductMatcherTest extends TestCase
{
    private ProductMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new ProductMatcher();
    }

    public function testFallsBackToSuggestionWhenNoSkuOrDescriptionMatch(): void
    {
        // Empty SKU and a too-short description skip the DB lookups, so the
        // supplier-history suggestion is the only candidate left (issue #53).
        $reference = $this->matcher->findReference(
            ['sku' => '', 'description' => 'ab'],
            'SUGGESTED-REF'
        );

        $this->assertSame('SUGGESTED-REF', $reference);
    }

    public function testReturnsNullWhenNoMatchAndNoSuggestion(): void
    {
        $reference = $this->matcher->findReference(['sku' => '', 'description' => 'ab']);

        $this->assertNull($reference);
    }

    public function testEmptySuggestionIsTreatedAsNoSuggestion(): void
    {
        $reference = $this->matcher->findReference(
            ['sku' => '', 'description' => 'ab'],
            '   '
        );

        $this->assertNull($reference);
    }
}
