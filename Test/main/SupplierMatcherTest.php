<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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
