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

use FacturaScripts\Plugins\AiScan\Lib\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator();
    }

    public function testValidateReturnsMissingInvoiceSectionError(): void
    {
        $errors = $this->validator->validate([]);
        $this->assertContains('Missing invoice section', $errors);
    }

    public function testValidateReturnsMissingRequiredFieldErrors(): void
    {
        $errors = $this->validator->validate(['invoice' => []]);
        $this->assertNotEmpty($errors);
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

    public function testNormalizeDecimalWithComma(): void
    {
        $data = $this->validator->normalize([
            'invoice' => [
                'total' => '1.234,56',
                'currency' => 'eur',
            ],
        ]);
        $this->assertEqualsWithDelta(1234.56, $data['invoice']['total'], 0.01);
        $this->assertSame('EUR', $data['invoice']['currency']);
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

        $this->assertContains('Currency must be a 3-letter ISO code', $errors);
        $this->assertContains('Missing line description at index 0', $errors);
    }
}
