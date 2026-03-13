<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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
            ],
        ]);
        $this->assertEqualsWithDelta(1234.56, $data['invoice']['total'], 0.01);
    }
}
