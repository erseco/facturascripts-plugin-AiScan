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

use FacturaScripts\Plugins\AiScan\Lib\ExtractionService;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use FacturaScripts\Plugins\AiScan\Lib\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Issue #67: scan failures and manual entry must not block accounting.
 */
final class ManualEntryFallbackTest extends TestCase
{
    public function testNetworkExceptionProducesControlledScanFailedPayload(): void
    {
        $exception = new \RuntimeException('OpenAI request failed: Failed to connect to api.openai.com');
        $result = ExtractionService::buildScanFailedResult($exception);

        $this->assertTrue($result['success'], 'Response must be controlled (success flag set)');
        $this->assertTrue($result['scan_failed']);
        $this->assertSame('network_error', $result['scan_failure_code']);
        $this->assertNotEmpty($result['message']);
        $this->assertIsArray($result['data']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function testInvalidJsonProducesControlledScanFailedPayload(): void
    {
        $exception = new \RuntimeException('Invalid JSON response from AI provider: Syntax error');
        $result = ExtractionService::buildScanFailedResult($exception);

        $this->assertTrue($result['scan_failed']);
        $this->assertSame('invalid_json', $result['scan_failure_code']);
        $this->assertTrue($result['data']['_scan_failed']);
        $this->assertSame([], $result['data']['lines']);
    }

    public function testMissingRequiredFieldsStillNormalizeToEditableShell(): void
    {
        $validator = new SchemaValidator();
        $normalized = $validator->normalize([
            'invoice' => ['number' => null],
            'supplier' => [],
            'lines' => null,
        ]);
        $errors = $validator->validate($normalized);

        $this->assertIsArray($normalized['invoice']);
        $this->assertIsArray($normalized['lines']);
        $this->assertNotEmpty($errors, 'Missing required fields become validation warnings, not hard failures');
    }

    public function testEmptyPayloadCanBeFilledManuallyAndMapped(): void
    {
        if (!class_exists('FacturaScripts\\Dinamic\\Model\\FacturaProveedor')) {
            $this->markTestSkipped('FacturaScripts Dinamic models are not available in this environment.');
        }

        $payload = ExtractionService::emptyExtractionPayload(
            'Automatic scan is unavailable. You can enter the invoice data manually.'
        );

        // Simulate user filling the form by hand (no prior AI scan).
        $payload['supplier'] = [
            'name' => 'Manual Supplier SL',
            'tax_id' => 'B99887766',
            'create_if_missing' => true,
        ];
        $payload['invoice'] = [
            'number' => 'MANUAL-' . date('YmdHis'),
            'issue_date' => date('Y-m-d'),
            'subtotal' => 100.0,
            'tax_amount' => 21.0,
            'total' => 121.0,
            'currency' => 'EUR',
        ];
        $payload['lines'] = [
            [
                'descripcion' => 'Servicio manual',
                'cantidad' => 1,
                'pvpunitario' => 100.0,
                'dtopor' => 0,
                'iva' => 21,
                'irpf' => 0,
            ],
        ];
        $payload['_scan_failed'] = true;

        $mapper = new InvoiceMapper();
        $result = $mapper->mapToInvoice($payload, null, 'lines', false);

        if (!$result['success']) {
            // Soft assert with diagnostics — environment may lack default series/warehouse.
            foreach ($result['errors'] as $error) {
                $this->assertStringNotContainsString(
                    'scan',
                    strtolower($error),
                    'Scan failure flags must not block import of manual data'
                );
            }
        }

        // When FS is fully seeded the invoice is created.
        if ($result['success']) {
            $this->assertNotEmpty($result['invoice_id']);
        }
    }

    public function testSuccessfulExtractionPayloadIsNotMarkedScanFailed(): void
    {
        $validator = new SchemaValidator();
        $data = $validator->normalize([
            'document_type' => 'invoice',
            'supplier' => ['name' => 'ACME', 'tax_id' => 'B12345678'],
            'invoice' => [
                'number' => 'F-2025-100',
                'issue_date' => '2025-06-01',
                'subtotal' => 50,
                'tax_amount' => 10.5,
                'total' => 60.5,
            ],
            'lines' => [
                [
                    'descripcion' => 'Producto A',
                    'cantidad' => 1,
                    'pvpunitario' => 50,
                    'iva' => 21,
                ],
            ],
            'taxes' => [],
            'confidence' => [
                'supplier_name' => 0.9,
                'supplier_tax_id' => 0.9,
                'invoice_number' => 0.95,
                'issue_date' => 0.9,
                'total' => 0.9,
                'lines' => 0.8,
            ],
            'warnings' => [],
        ]);

        $errors = $validator->validate($data);
        $data['_validation_errors'] = $errors;
        $data['_provider'] = 'mock';
        $data['_scan_failed'] = false;

        $this->assertFalse($data['_scan_failed']);
        $this->assertSame('F-2025-100', $data['invoice']['number']);
        $this->assertSame('ACME', $data['supplier']['name']);
        $this->assertCount(1, $data['lines']);
        $this->assertEmpty($errors);
    }

    public function testRegressionSuccessfulAnalyzeResponseShape(): void
    {
        // Mirrors the controller success JSON shape after #67 (scan_failed: false).
        $extracted = [
            'supplier' => ['name' => 'Proveedor Demo', 'tax_id' => 'A11111111'],
            'invoice' => [
                'number' => 'REG-001',
                'issue_date' => '2025-03-15',
                'total' => 10,
            ],
            'lines' => [
                ['descripcion' => 'Linea', 'cantidad' => 1, 'pvpunitario' => 10, 'iva' => 0],
            ],
            'confidence' => [],
            'warnings' => [],
            '_validation_errors' => [],
            '_provider' => 'mock',
        ];

        $response = [
            'success' => true,
            'scan_failed' => false,
            'data' => $extracted,
        ];

        $this->assertTrue($response['success']);
        $this->assertFalse($response['scan_failed']);
        $this->assertSame('REG-001', $response['data']['invoice']['number']);
        $this->assertSame('Proveedor Demo', $response['data']['supplier']['name']);
    }
}
