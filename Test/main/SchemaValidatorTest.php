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

use FacturaScripts\Plugins\AiScan\Lib\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator();
    }

    // ── validate() ──────────────────────────────────────────

    public function testValidateReturnsMissingInvoiceSectionError(): void
    {
        $errors = $this->validator->validate([]);
        $this->assertContains('Missing invoice section', $errors);
    }

    public function testValidateReturnsMissingRequiredFieldErrors(): void
    {
        $errors = $this->validator->validate(['invoice' => []]);
        $this->assertNotEmpty($errors);
        $this->assertContains('Missing required invoice field: number', $errors);
        $this->assertContains('Missing required invoice field: issue_date', $errors);
        $this->assertContains('Missing required invoice field: total', $errors);
    }

    public function testValidatePassesWithRequiredFields(): void
    {
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'total' => 121.0,
            ],
        ]);
        $this->assertEmpty($errors);
    }

    public function testValidateDetectsTaxMismatch(): void
    {
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'subtotal' => 100.0,
                'tax_amount' => 21.0,
                'total' => 200.0,
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('mismatch', $errors[0]);
    }

    public function testValidatePassesWhenArithmeticIsCorrect(): void
    {
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'subtotal' => 100.0,
                'tax_amount' => 21.0,
                'total' => 121.0,
            ],
        ]);
        $this->assertEmpty($errors);
    }

    public function testValidateAccountsForWithholdingInArithmetic(): void
    {
        // 100 + 21 - 15 = 106
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'subtotal' => 100.0,
                'tax_amount' => 21.0,
                'withholding_amount' => 15.0,
                'total' => 106.0,
            ],
        ]);
        $this->assertEmpty($errors);
    }

    public function testValidateDetectsWithholdingMismatch(): void
    {
        // 100 + 21 - 15 = 106 but total says 121
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'subtotal' => 100.0,
                'tax_amount' => 21.0,
                'withholding_amount' => 15.0,
                'total' => 121.0,
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('mismatch', $errors[0]);
    }

    public function testValidateToleratesSmallRounding(): void
    {
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'subtotal' => 100.0,
                'tax_amount' => 21.0,
                'total' => 121.01,
            ],
        ]);
        $this->assertEmpty($errors);
    }

    public function testValidateRejectsInvalidDateFormat(): void
    {
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '01/01/2025',
                'total' => 100.0,
            ],
        ]);
        $this->assertContains(
            'Issue date must use YYYY-MM-DD format',
            $errors
        );
    }

    public function testValidateRejectsInvalidDueDateFormat(): void
    {
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'due_date' => '31-01-2025',
                'total' => 100.0,
            ],
        ]);
        $this->assertContains(
            'Due date must use YYYY-MM-DD format',
            $errors
        );
    }

    public function testValidateAcceptsValidDueDate(): void
    {
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'due_date' => '2025-02-01',
                'total' => 100.0,
            ],
        ]);
        $this->assertEmpty($errors);
    }

    public function testValidateDetectsInvalidCurrencyAndLineDescription(): void
    {
        $errors = $this->validator->validate([
            'supplier' => [],
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'currency' => 'EURO',
                'total' => 10,
            ],
            'taxes' => [],
            'lines' => [[]],
            'meta' => [],
        ]);

        $this->assertContains(
            'Currency must be a 3-letter ISO code',
            $errors
        );
        $this->assertContains(
            'Missing line description at index 0',
            $errors
        );
    }

    public function testValidateAcceptsValidCurrency(): void
    {
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'currency' => 'EUR',
                'total' => 100.0,
            ],
        ]);
        $this->assertEmpty($errors);
    }

    public function testValidateRejectsTaxesAsNonArray(): void
    {
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'total' => 100.0,
            ],
            'taxes' => 'invalid',
        ]);
        $this->assertContains('Taxes must be an array', $errors);
    }

    public function testValidateRejectsLinesAsNonArray(): void
    {
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'total' => 100.0,
            ],
            'lines' => 'invalid',
        ]);
        $this->assertContains('Lines must be an array', $errors);
    }

    public function testValidateIncludesAiWarnings(): void
    {
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'total' => 100.0,
            ],
            'warnings' => [
                'Ambiguous supplier name',
                'Date inferred from context',
            ],
        ]);
        $this->assertContains('Ambiguous supplier name', $errors);
        $this->assertContains('Date inferred from context', $errors);
    }

    public function testValidateIgnoresEmptyWarnings(): void
    {
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'total' => 100.0,
            ],
            'warnings' => ['', null, 0],
        ]);
        $this->assertEmpty($errors);
    }

    public function testValidateMultipleLineDescriptions(): void
    {
        $errors = $this->validator->validate([
            'invoice' => [
                'number' => 'INV-001',
                'issue_date' => '2025-01-01',
                'total' => 100.0,
            ],
            'lines' => [
                ['description' => 'Valid line'],
                [],
                ['description' => ''],
            ],
        ]);
        $this->assertContains(
            'Missing line description at index 1',
            $errors
        );
        $this->assertContains(
            'Missing line description at index 2',
            $errors
        );
        $this->assertNotContains(
            'Missing line description at index 0',
            $errors
        );
    }

    // ── normalize() ─────────────────────────────────────────

    public function testNormalizeDateFormats(): void
    {
        $data = $this->validator->normalize([
            'invoice' => [
                'issue_date' => '01/01/2025',
                'total' => 100,
            ],
        ]);
        $this->assertEquals('2025-01-01', $data['invoice']['issue_date']);
    }

    public function testNormalizeDueDateFormat(): void
    {
        $data = $this->validator->normalize([
            'invoice' => [
                'due_date' => '15-03-2025',
            ],
        ]);
        $this->assertEquals('2025-03-15', $data['invoice']['due_date']);
    }

    public function testNormalizeDateWithDotSeparator(): void
    {
        $data = $this->validator->normalize([
            'invoice' => [
                'issue_date' => '25.12.2024',
            ],
        ]);
        $this->assertEquals('2024-12-25', $data['invoice']['issue_date']);
    }

    public function testNormalizeDateAlreadyCorrectFormat(): void
    {
        $data = $this->validator->normalize([
            'invoice' => [
                'issue_date' => '2025-06-15',
            ],
        ]);
        $this->assertEquals('2025-06-15', $data['invoice']['issue_date']);
    }

    public function testNormalizeDecimalWithComma(): void
    {
        $data = $this->validator->normalize([
            'invoice' => [
                'total' => '1.234,56',
                'currency' => 'eur',
            ],
        ]);
        $this->assertEqualsWithDelta(
            1234.56,
            $data['invoice']['total'],
            0.01
        );
        $this->assertSame('EUR', $data['invoice']['currency']);
    }

    public function testNormalizeEuropeanDecimalWithoutCents(): void
    {
        $data = $this->validator->normalize([
            'invoice' => [
                'subtotal' => '1.234',
                'total' => '2.345,00',
            ],
        ]);
        // '1.234' is ambiguous but not matching European pattern
        // '2.345,00' matches European pattern
        $this->assertEqualsWithDelta(
            2345.00,
            $data['invoice']['total'],
            0.01
        );
    }

    public function testNormalizeAllMonetaryFields(): void
    {
        $data = $this->validator->normalize([
            'invoice' => [
                'subtotal' => '100,50',
                'tax_amount' => '21,10',
                'withholding_amount' => '15,00',
                'total' => '106,60',
            ],
        ]);
        $this->assertEqualsWithDelta(
            100.50,
            $data['invoice']['subtotal'],
            0.01
        );
        $this->assertEqualsWithDelta(
            21.10,
            $data['invoice']['tax_amount'],
            0.01
        );
        $this->assertEqualsWithDelta(
            15.00,
            $data['invoice']['withholding_amount'],
            0.01
        );
        $this->assertEqualsWithDelta(
            106.60,
            $data['invoice']['total'],
            0.01
        );
    }

    public function testNormalizeIntegerValues(): void
    {
        $data = $this->validator->normalize([
            'invoice' => ['total' => 100],
        ]);
        $this->assertSame(100.0, $data['invoice']['total']);
    }

    public function testNormalizeCurrencySymbols(): void
    {
        $cases = [
            '€' => 'EUR',
            '$' => 'USD',
            '£' => 'GBP',
            '¥' => 'JPY',
            'eur' => 'EUR',
            'usd' => 'USD',
        ];

        foreach ($cases as $input => $expected) {
            $data = $this->validator->normalize([
                'invoice' => ['currency' => $input],
            ]);
            $this->assertSame(
                $expected,
                $data['invoice']['currency'],
                "Currency '$input' should normalize to '$expected'"
            );
        }
    }

    public function testNormalizeInitializesMissingSections(): void
    {
        $data = $this->validator->normalize([]);
        $this->assertIsArray($data['supplier']);
        $this->assertIsArray($data['invoice']);
        $this->assertIsArray($data['taxes']);
        $this->assertIsArray($data['lines']);
        $this->assertIsArray($data['warnings']);
        $this->assertIsArray($data['confidence']);
        $this->assertIsArray($data['customer']);
    }

    public function testNormalizeCoercesNonArraySections(): void
    {
        $data = $this->validator->normalize([
            'supplier' => 'invalid',
            'taxes' => 'not an array',
            'lines' => 42,
            'warnings' => null,
            'confidence' => false,
            'customer' => '',
        ]);
        $this->assertIsArray($data['supplier']);
        $this->assertIsArray($data['taxes']);
        $this->assertIsArray($data['lines']);
        $this->assertIsArray($data['warnings']);
        $this->assertIsArray($data['confidence']);
        $this->assertIsArray($data['customer']);
    }

    public function testNormalizeLineItemDecimals(): void
    {
        $data = $this->validator->normalize([
            'lines' => [
                [
                    'description' => 'Widget',
                    'quantity' => '2,5',
                    'unit_price' => '10,00',
                    'discount' => '5',
                    'tax_rate' => '21,00',
                    'line_total' => '25,00',
                ],
            ],
        ]);
        $line = $data['lines'][0];
        $this->assertEqualsWithDelta(2.5, $line['cantidad'], 0.01);
        $this->assertEqualsWithDelta(10.0, $line['pvpunitario'], 0.01);
        $this->assertEqualsWithDelta(5.0, $line['dtopor'], 0.01);
        $this->assertEqualsWithDelta(21.0, $line['iva'], 0.01);
        $this->assertEqualsWithDelta(25.0, $line['pvptotal'], 0.01);
    }

    public function testNormalizeTaxDecimals(): void
    {
        $data = $this->validator->normalize([
            'taxes' => [
                [
                    'name' => 'IVA',
                    'rate' => '21,00',
                    'base' => '1.000,00',
                    'amount' => '210,00',
                ],
            ],
        ]);
        $tax = $data['taxes'][0];
        $this->assertEqualsWithDelta(21.0, $tax['rate'], 0.01);
        $this->assertEqualsWithDelta(1000.0, $tax['base'], 0.01);
        $this->assertEqualsWithDelta(210.0, $tax['amount'], 0.01);
    }

    public function testNormalizePreservesNonNumericFields(): void
    {
        $data = $this->validator->normalize([
            'supplier' => [
                'name' => 'Acme Corp S.L.',
                'tax_id' => 'B12345678',
            ],
            'invoice' => [
                'number' => 'F-2025/001',
                'summary' => 'Office supplies',
            ],
        ]);
        $this->assertSame('Acme Corp S.L.', $data['supplier']['name']);
        $this->assertSame('B12345678', $data['supplier']['tax_id']);
        $this->assertSame('F-2025/001', $data['invoice']['number']);
        $this->assertSame('Office supplies', $data['invoice']['summary']);
    }

    public function testNormalizeSkipsEmptyDates(): void
    {
        $data = $this->validator->normalize([
            'invoice' => [
                'issue_date' => '',
                'due_date' => null,
            ],
        ]);
        $this->assertSame('', $data['invoice']['issue_date']);
        $this->assertNull($data['invoice']['due_date']);
    }

    public function testNormalizeMultipleLines(): void
    {
        $data = $this->validator->normalize([
            'lines' => [
                ['description' => 'A', 'quantity' => 1, 'unit_price' => 10],
                ['description' => 'B', 'quantity' => '3,5', 'unit_price' => '20,00'],
            ],
        ]);
        $this->assertCount(2, $data['lines']);
        $this->assertEqualsWithDelta(
            1.0,
            $data['lines'][0]['cantidad'],
            0.01
        );
        $this->assertEqualsWithDelta(
            3.5,
            $data['lines'][1]['cantidad'],
            0.01
        );
    }
}
