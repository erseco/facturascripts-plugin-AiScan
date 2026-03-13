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

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use PHPUnit\Framework\TestCase;

final class InvoiceMapperTest extends TestCase
{
    private InvoiceMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new InvoiceMapper();
    }

    public function testCanInstantiate(): void
    {
        $this->assertInstanceOf(InvoiceMapper::class, $this->mapper);
    }

    // ── buildNotes() ────────────────────────────────────────

    public function testBuildNotesWithAllFields(): void
    {
        $result = $this->callBuildNotes([
            'summary' => 'Office supplies',
            'payment_terms' => 'Net 30',
            'notes' => 'Urgent delivery',
        ]);
        $this->assertStringContainsString('Office supplies', $result);
        $this->assertStringContainsString('Net 30', $result);
        $this->assertStringContainsString('Urgent delivery', $result);
    }

    public function testBuildNotesWithEmptyFields(): void
    {
        $result = $this->callBuildNotes([
            'summary' => '',
            'payment_terms' => '',
            'notes' => '',
        ]);
        $this->assertSame('', $result);
    }

    public function testBuildNotesWithPartialFields(): void
    {
        $result = $this->callBuildNotes([
            'summary' => 'Monthly subscription',
        ]);
        $this->assertSame('Monthly subscription', $result);
    }

    public function testBuildNotesDeduplicates(): void
    {
        $result = $this->callBuildNotes([
            'summary' => 'Same text',
            'notes' => 'Same text',
        ]);
        $this->assertSame('Same text', $result);
    }

    public function testBuildNotesTrimsWhitespace(): void
    {
        $result = $this->callBuildNotes([
            'summary' => '  trimmed  ',
        ]);
        $this->assertSame('trimmed', $result);
    }

    // ── prepareLines() ──────────────────────────────────────

    public function testPrepareLinesReturnsExistingLines(): void
    {
        $lines = [
            ['description' => 'Widget', 'quantity' => 2, 'unit_price' => 10],
        ];
        $result = $this->callPrepareLines($lines, []);
        $this->assertSame($lines, $result);
    }

    public function testPrepareLinesCreatesFallbackFromInvoiceData(): void
    {
        $result = $this->callPrepareLines([], [
            'subtotal' => 100.0,
            'tax_amount' => 21.0,
            'total' => 121.0,
            'summary' => 'Consulting services',
        ]);
        $this->assertCount(1, $result);
        $this->assertSame('Consulting services', $result[0]['description']);
        $this->assertSame(1, $result[0]['quantity']);
        $this->assertEqualsWithDelta(100.0, $result[0]['unit_price'], 0.01);
        $this->assertEqualsWithDelta(21.0, $result[0]['tax_rate'], 0.01);
    }

    public function testPrepareLinesUsesTotalWhenNoSubtotal(): void
    {
        $result = $this->callPrepareLines([], [
            'total' => 50.0,
        ]);
        $this->assertCount(1, $result);
        $this->assertEqualsWithDelta(50.0, $result[0]['unit_price'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result[0]['tax_rate'], 0.01);
    }

    public function testPrepareLinesDefaultDescription(): void
    {
        $result = $this->callPrepareLines([], ['total' => 10]);
        $this->assertSame(
            Tools::lang()->trans('aiscan-scanned-supplier-invoice'),
            $result[0]['description']
        );
    }

    public function testPrepareLinesCalculatesTaxRate(): void
    {
        // subtotal 200, tax 42 → 21% tax rate
        $result = $this->callPrepareLines([], [
            'subtotal' => 200.0,
            'tax_amount' => 42.0,
            'total' => 242.0,
        ]);
        $this->assertEqualsWithDelta(21.0, $result[0]['tax_rate'], 0.01);
    }

    public function testPrepareLinesZeroSubtotalNoTaxRate(): void
    {
        $result = $this->callPrepareLines([], [
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);
        $this->assertEqualsWithDelta(0.0, $result[0]['tax_rate'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result[0]['unit_price'], 0.01);
    }

    public function testValidateInvoiceDataRequiresWarehouseForNewInvoice(): void
    {
        $result = $this->callValidateInvoiceData([], null);

        $this->assertSame([Tools::lang()->trans('aiscan-warehouse-required')], $result);
    }

    public function testValidateInvoiceDataAllowsWarehouseForNewInvoice(): void
    {
        $result = $this->callValidateInvoiceData(['warehouse_code' => '  MAIN  '], null);

        $this->assertSame([], $result);
        $this->assertSame('MAIN', $this->callGetWarehouseCode(['warehouse_code' => '  MAIN  ']));
    }

    public function testValidateInvoiceDataDoesNotRequireWarehouseForExistingInvoice(): void
    {
        $result = $this->callValidateInvoiceData([], 12);

        $this->assertSame([], $result);
    }

    // ── helpers ──────────────────────────────────────────────

    private function callBuildNotes(array $invoiceData): string
    {
        $method = new \ReflectionMethod(InvoiceMapper::class, 'buildNotes');
        $method->setAccessible(true);
        return $method->invoke($this->mapper, $invoiceData);
    }

    private function callPrepareLines(array $lines, array $invoiceData): array
    {
        $method = new \ReflectionMethod(InvoiceMapper::class, 'prepareLines');
        $method->setAccessible(true);
        return $method->invoke($this->mapper, $lines, $invoiceData);
    }

    private function callValidateInvoiceData(array $invoiceData, ?int $invoiceId): array
    {
        $method = new \ReflectionMethod(InvoiceMapper::class, 'validateInvoiceData');
        $method->setAccessible(true);
        return $method->invoke($this->mapper, $invoiceData, $invoiceId);
    }

    private function callGetWarehouseCode(array $invoiceData): string
    {
        $method = new \ReflectionMethod(InvoiceMapper::class, 'getWarehouseCode');
        $method->setAccessible(true);
        return $method->invoke($this->mapper, $invoiceData);
    }
}
