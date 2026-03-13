<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use PHPUnit\Framework\TestCase;

final class InvoiceMapperTest extends TestCase
{
    public function testCanInstantiate(): void
    {
        $mapper = new InvoiceMapper();
        $this->assertInstanceOf(InvoiceMapper::class, $mapper);
    }
}
