<?php

/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\AiScan\Lib\ProductMatcher;
use PHPUnit\Framework\TestCase;

final class ProductMatcherTest extends TestCase
{
    public function testMatchesProductByNormalizedSkuBeforeFallback(): void
    {
        $matcher = new class extends ProductMatcher {
            public array $calls = [];

            protected function findVariantsBySku(string $sku, int $limit = 20): array
            {
                $this->calls[] = 'sku';
                return [(object) ['referencia' => 'ABC123', 'codbarras' => '', 'descripcion' => 'Widget Pro']];
            }

            protected function findVariantsByDescription(string $description): array
            {
                $this->calls[] = 'description';
                return [];
            }
        };

        $reference = $matcher->findReference([
            'sku' => 'abc-123',
            'description' => 'Widget Pro',
        ]);

        $this->assertSame('ABC123', $reference);
        $this->assertSame(['sku'], $matcher->calls);
    }

    public function testMatchesProductByExactDescription(): void
    {
        $matcher = new class extends ProductMatcher {
            protected function findVariantsBySku(string $sku, int $limit = 20): array
            {
                return [];
            }

            protected function findVariantsByDescription(string $description): array
            {
                return [(object) ['code' => 'WIDGET-01', 'description' => 'Widget Pro 500']];
            }
        };

        $reference = $matcher->findReference([
            'sku' => '',
            'description' => 'Widget Pro 500',
        ]);

        $this->assertSame('WIDGET-01', $reference);
    }

    public function testReturnsNullWhenProductMatchIsAmbiguous(): void
    {
        $matcher = new class extends ProductMatcher {
            protected function findVariantsBySku(string $sku, int $limit = 20): array
            {
                return [];
            }

            protected function findVariantsByDescription(string $description): array
            {
                return [
                    (object) ['code' => 'WIDGET-01', 'description' => 'Widget'],
                    (object) ['code' => 'WIDGET-02', 'description' => 'Widget'],
                ];
            }
        };

        $reference = $matcher->findReference([
            'sku' => '',
            'description' => 'Widget',
        ]);

        $this->assertNull($reference);
    }

    public function testReturnsNullWhenNoProductMatches(): void
    {
        $matcher = new class extends ProductMatcher {
            protected function findVariantsBySku(string $sku, int $limit = 20): array
            {
                return [];
            }

            protected function findVariantsByDescription(string $description): array
            {
                return [];
            }
        };

        $reference = $matcher->findReference([
            'sku' => '',
            'description' => 'Unknown line item',
        ]);

        $this->assertNull($reference);
    }
}
