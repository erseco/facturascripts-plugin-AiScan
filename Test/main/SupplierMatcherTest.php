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
use PHPUnit\Framework\TestCase;

final class SupplierMatcherTest extends TestCase
{
    private SupplierMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SupplierMatcher();
    }

    public function testReturnsNotFoundWhenNoData(): void
    {
        $result = $this->matcher->findMatch(['name' => '', 'tax_id' => '']);
        $this->assertEquals('not_found', $result['match_status']);
        $this->assertNull($result['supplier']);
    }

    public function testCanInstantiate(): void
    {
        $this->assertInstanceOf(SupplierMatcher::class, $this->matcher);
    }
}
