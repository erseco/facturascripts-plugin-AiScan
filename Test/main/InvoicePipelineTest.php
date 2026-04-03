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

use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use FacturaScripts\Plugins\AiScan\Lib\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the full AI response → normalize → validate → prepare pipeline
 * using fixture JSON files that simulate real AI provider responses.
 *
 * These tests do NOT call any AI provider. They verify that once the AI
 * returns a JSON response, the rest of the pipeline handles it correctly.
 */
final class InvoicePipelineTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator();
    }

    private static function fixturesDir(): string
    {
        // Tests run from Test/Plugins/ but fixtures live in the plugin dir
        $pluginPath = defined('FS_FOLDER')
            ? FS_FOLDER . '/Plugins/AiScan/Test/fixtures/responses'
            : dirname(__DIR__, 2) . '/Test/fixtures/responses';

        // Fallback: resolve relative to the copied test location
        if (!is_dir($pluginPath)) {
            $pluginPath = dirname(__DIR__) . '/fixtures/responses';
        }

        return $pluginPath;
    }

    private static function loadFixture(string $name): array
    {
        $path = self::fixturesDir() . '/' . $name . '.json';
        if (!file_exists($path)) {
            throw new \RuntimeException("Fixture not found: {$path}");
        }
        return json_decode(file_get_contents($path), true);
    }

    public static function invoiceFixtureProvider(): array
    {
        return [
            'simple-1-line-no-retention' => ['F-2024-011'],
            'multi-line-with-retention' => ['F-2024-004'],
            'two-lines-same-tax' => ['F-2024-012'],
            'two-lines-mixed-tax-with-retention' => ['F-2024-014'],
            'simple-1-line-low-amount' => ['F-2024-021'],
        ];
    }

    // ── Normalization tests ────────────────────────────────────────────

    #[DataProvider('invoiceFixtureProvider')]
    public function testNormalizePreservesRequiredSections(string $fixture): void
    {
        $raw = self::loadFixture($fixture);
        $normalized = $this->validator->normalize($raw);

        $this->assertIsArray($normalized['supplier']);
        $this->assertIsArray($normalized['invoice']);
        $this->assertIsArray($normalized['taxes']);
        $this->assertIsArray($normalized['lines']);
        $this->assertIsArray($normalized['confidence']);
        $this->assertIsArray($normalized['warnings']);
    }

    #[DataProvider('invoiceFixtureProvider')]
    public function testNormalizeDateFormat(string $fixture): void
    {
        $raw = self::loadFixture($fixture);
        $normalized = $this->validator->normalize($raw);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            $normalized['invoice']['issue_date'],
            'Issue date must be YYYY-MM-DD after normalization'
        );
    }

    #[DataProvider('invoiceFixtureProvider')]
    public function testNormalizeDecimalFields(string $fixture): void
    {
        $raw = self::loadFixture($fixture);
        $normalized = $this->validator->normalize($raw);

        $this->assertIsFloat($normalized['invoice']['subtotal']);
        $this->assertIsFloat($normalized['invoice']['tax_amount']);
        $this->assertIsFloat($normalized['invoice']['total']);

        foreach ($normalized['lines'] as $line) {
            if (isset($line['unit_price'])) {
                $this->assertIsFloat($line['unit_price']);
            }
            if (isset($line['quantity'])) {
                $this->assertIsFloat($line['quantity']);
            }
        }
    }

    // ── Validation tests ───────────────────────────────────────────────

    #[DataProvider('invoiceFixtureProvider')]
    public function testValidateAcceptsWellFormedFixture(string $fixture): void
    {
        $raw = self::loadFixture($fixture);
        $normalized = $this->validator->normalize($raw);
        $errors = $this->validator->validate($normalized);

        $this->assertEmpty(
            $errors,
            "Fixture {$fixture} should have no validation errors, got: "
            . implode('; ', $errors)
        );
    }

    public function testValidateRejectsMissingInvoiceNumber(): void
    {
        $raw = self::loadFixture('F-2024-011');
        $raw['invoice']['number'] = null;
        $normalized = $this->validator->normalize($raw);
        $errors = $this->validator->validate($normalized);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('number', $errors[0]);
    }

    public function testValidateRejectsMissingTotal(): void
    {
        $raw = self::loadFixture('F-2024-011');
        $raw['invoice']['total'] = null;
        $normalized = $this->validator->normalize($raw);
        $errors = $this->validator->validate($normalized);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('total', $errors[0]);
    }

    public function testValidateRejectsBadDateFormat(): void
    {
        $raw = self::loadFixture('F-2024-011');
        $raw['invoice']['issue_date'] = 'not-a-date';
        $normalized = $this->validator->normalize($raw);
        $errors = $this->validator->validate($normalized);

        $foundDateError = false;
        foreach ($errors as $error) {
            if (stripos($error, 'date') !== false) {
                $foundDateError = true;
                break;
            }
        }
        $this->assertTrue($foundDateError, 'Expected a date-related validation error');
    }

    // ── Arithmetic consistency tests ───────────────────────────────────

    #[DataProvider('invoiceFixtureProvider')]
    public function testArithmeticConsistency(string $fixture): void
    {
        $raw = self::loadFixture($fixture);
        $normalized = $this->validator->normalize($raw);
        $invoice = $normalized['invoice'];

        $computed = $invoice['subtotal'] + $invoice['tax_amount']
            - (float) ($invoice['withholding_amount'] ?? 0);

        $this->assertEqualsWithDelta(
            $invoice['total'],
            $computed,
            0.02,
            "subtotal({$invoice['subtotal']}) + tax({$invoice['tax_amount']})"
            . ' - withholding(' . ($invoice['withholding_amount'] ?? 0) . ')'
            . " should equal total({$invoice['total']})"
        );
    }

    #[DataProvider('invoiceFixtureProvider')]
    public function testLinesTotalsMatchSubtotal(string $fixture): void
    {
        $raw = self::loadFixture($fixture);
        $normalized = $this->validator->normalize($raw);
        $lines = $normalized['lines'];
        $invoice = $normalized['invoice'];

        if (empty($lines)) {
            $this->markTestSkipped("No lines in fixture {$fixture}");
        }

        $linesSubtotal = 0.0;
        foreach ($lines as $line) {
            $linesSubtotal += (float) ($line['unit_price'] ?? 0)
                * (float) ($line['quantity'] ?? 1);
        }

        $this->assertEqualsWithDelta(
            $invoice['subtotal'],
            $linesSubtotal,
            0.02,
            'Sum of line totals should match invoice subtotal'
        );
    }

    // ── Tax breakdown tests ────────────────────────────────────────────

    #[DataProvider('invoiceFixtureProvider')]
    public function testTaxBreakdownMatchesTotalTax(string $fixture): void
    {
        $raw = self::loadFixture($fixture);
        $normalized = $this->validator->normalize($raw);

        if (empty($normalized['taxes'])) {
            $this->markTestSkipped("No tax breakdown in fixture {$fixture}");
        }

        $taxSum = 0.0;
        foreach ($normalized['taxes'] as $tax) {
            $taxSum += (float) ($tax['amount'] ?? 0);
        }

        $this->assertEqualsWithDelta(
            $normalized['invoice']['tax_amount'],
            $taxSum,
            0.02,
            'Sum of tax breakdown amounts should match invoice tax_amount'
        );
    }

    public function testMultipleTaxRatesPresent(): void
    {
        $normalized = $this->validator->normalize(self::loadFixture('F-2024-004'));

        $rates = array_map(fn ($t) => (float) $t['rate'], $normalized['taxes']);
        $this->assertContains(7.0, $rates);
        $this->assertContains(3.0, $rates);
        $this->assertCount(2, $rates);
    }

    // ── Withholding tests ──────────────────────────────────────────────

    public function testWithholdingReducesTotal(): void
    {
        $normalized = $this->validator->normalize(self::loadFixture('F-2024-004'));
        $invoice = $normalized['invoice'];

        $this->assertGreaterThan(0, $invoice['withholding_amount']);
        $this->assertLessThan(
            $invoice['subtotal'] + $invoice['tax_amount'],
            $invoice['total'],
            'Total should be less than subtotal + tax when withholding is present'
        );
    }

    public function testNoWithholdingMeansSubtotalPlusTaxEqualsTotal(): void
    {
        $normalized = $this->validator->normalize(self::loadFixture('F-2024-012'));
        $invoice = $normalized['invoice'];

        $this->assertEqualsWithDelta(
            0.0,
            (float) ($invoice['withholding_amount'] ?? 0),
            0.001
        );
        $this->assertEqualsWithDelta(
            $invoice['subtotal'] + $invoice['tax_amount'],
            $invoice['total'],
            0.02
        );
    }

    // ── InvoiceMapper prepareLines tests ───────────────────────────────

    public function testPrepareLinesFallbackWhenNoLines(): void
    {
        $mapper = new InvoiceMapper();
        $method = new \ReflectionMethod(InvoiceMapper::class, 'prepareLines');

        $invoiceData = [
            'subtotal' => 800.00,
            'tax_amount' => 56.00,
            'total' => 856.00,
            'summary' => 'Test invoice summary',
        ];
        $result = $method->invoke($mapper, [], $invoiceData);

        $this->assertCount(1, $result);
        $this->assertSame('Test invoice summary', $result[0]['description']);
        $this->assertSame(1, $result[0]['quantity']);
        $this->assertSame(800.00, $result[0]['unit_price']);
        $this->assertSame(7.0, $result[0]['tax_rate']);
    }

    public function testPrepareLinesPassesThroughExistingLines(): void
    {
        $mapper = new InvoiceMapper();
        $method = new \ReflectionMethod(InvoiceMapper::class, 'prepareLines');

        $lines = [
            ['description' => 'Line 1', 'quantity' => 2, 'unit_price' => 100],
            ['description' => 'Line 2', 'quantity' => 1, 'unit_price' => 50],
        ];
        $result = $method->invoke($mapper, $lines, ['total' => 250]);

        $this->assertCount(2, $result);
        $this->assertSame('Line 1', $result[0]['description']);
        $this->assertSame('Line 2', $result[1]['description']);
    }

    // ── InvoiceMapper tax rate computation ──────────────────────────────

    public function testComputeTaxRateFromInvoiceData(): void
    {
        $mapper = new InvoiceMapper();
        $method = new \ReflectionMethod(InvoiceMapper::class, 'computeTaxRate');

        $this->assertSame(7.0, $method->invoke($mapper, [
            'subtotal' => 800.00,
            'tax_amount' => 56.00,
        ]));

        $this->assertSame(3.0, $method->invoke($mapper, [
            'subtotal' => 1000.00,
            'tax_amount' => 30.00,
        ]));

        $this->assertSame(0.0, $method->invoke($mapper, [
            'subtotal' => 500.00,
            'tax_amount' => 0,
        ]));
    }

    // ── Supplier data extraction tests ─────────────────────────────────

    #[DataProvider('invoiceFixtureProvider')]
    public function testSupplierDataPresent(string $fixture): void
    {
        $data = self::loadFixture($fixture);

        $this->assertNotEmpty($data['supplier']['name']);
        $this->assertNotEmpty($data['supplier']['tax_id']);
    }

    public function testSupplierTaxIdFormats(): void
    {
        $fixtures = ['F-2024-011', 'F-2024-004', 'F-2024-012', 'F-2024-014', 'F-2024-021'];
        foreach ($fixtures as $fixture) {
            $data = self::loadFixture($fixture);
            $taxId = $data['supplier']['tax_id'];
            $this->assertMatchesRegularExpression(
                '/^[A-Z0-9]{8,12}$/',
                $taxId,
                "Tax ID '{$taxId}' in {$fixture} should match Spanish CIF/NIF format"
            );
        }
    }

    // ── Confidence scores tests ────────────────────────────────────────

    #[DataProvider('invoiceFixtureProvider')]
    public function testConfidenceScoresInRange(string $fixture): void
    {
        $data = self::loadFixture($fixture);

        foreach ($data['confidence'] as $field => $score) {
            $this->assertGreaterThanOrEqual(0, $score, "{$field} confidence >= 0");
            $this->assertLessThanOrEqual(1, $score, "{$field} confidence <= 1");
        }
    }

    // ── European number format normalization ───────────────────────────

    public function testNormalizeEuropeanDecimalFormat(): void
    {
        $raw = self::loadFixture('F-2024-011');
        $raw['invoice']['subtotal'] = '1.350,00';
        $raw['invoice']['tax_amount'] = '94,50';
        $raw['invoice']['total'] = '1.444,50';

        $normalized = $this->validator->normalize($raw);

        $this->assertSame(1350.0, $normalized['invoice']['subtotal']);
        $this->assertSame(94.5, $normalized['invoice']['tax_amount']);
        $this->assertSame(1444.5, $normalized['invoice']['total']);
    }

    public function testNormalizeSpanishDateFormat(): void
    {
        $raw = self::loadFixture('F-2024-011');
        $raw['invoice']['issue_date'] = '28/01/2026';

        $normalized = $this->validator->normalize($raw);

        $this->assertSame('2026-01-28', $normalized['invoice']['issue_date']);
    }

    // ── Edge cases ─────────────────────────────────────────────────────

    public function testEmptyLinesArrayIsValid(): void
    {
        $raw = self::loadFixture('F-2024-011');
        $raw['lines'] = [];

        $normalized = $this->validator->normalize($raw);
        $errors = $this->validator->validate($normalized);

        $this->assertEmpty(array_filter($errors, fn ($e) => str_contains($e, 'line')));
    }

    public function testZeroWithholdingTreatedAsNoWithholding(): void
    {
        $raw = self::loadFixture('F-2024-012');
        $normalized = $this->validator->normalize($raw);

        $this->assertEqualsWithDelta(
            0.0,
            $normalized['invoice']['withholding_amount'],
            0.001
        );
    }

    public function testCurrencyDefaultsPreserved(): void
    {
        $raw = self::loadFixture('F-2024-011');
        $normalized = $this->validator->normalize($raw);

        $this->assertSame('EUR', $normalized['invoice']['currency']);
    }
}
