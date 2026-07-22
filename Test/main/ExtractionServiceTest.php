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
use PHPUnit\Framework\TestCase;

final class ExtractionServiceTest extends TestCase
{
    public function testGetDefaultSystemPromptIsNotEmpty(): void
    {
        $prompt = ExtractionService::getDefaultSystemPrompt();
        $this->assertNotEmpty($prompt);
        $this->assertStringContainsString('invoice', $prompt);
    }

    public function testDefaultSystemPromptContainsSchemaFields(): void
    {
        $prompt = ExtractionService::getDefaultSystemPrompt();
        $this->assertStringContainsString('supplier', $prompt);
        $this->assertStringContainsString('tax_id', $prompt);
        $this->assertStringContainsString('invoice', $prompt);
        $this->assertStringContainsString('lines', $prompt);
        $this->assertStringContainsString('confidence', $prompt);
        $this->assertStringContainsString('warnings', $prompt);
    }

    public function testDefaultSystemPromptContainsSpainRules(): void
    {
        $prompt = ExtractionService::getDefaultSystemPrompt();
        $this->assertStringContainsString('IVA', $prompt);
        $this->assertStringContainsString('IRPF', $prompt);
        $this->assertStringContainsString('NIF', $prompt);
        $this->assertStringContainsString('CIF', $prompt);
    }

    public function testGetExecutionPromptReplacesPlaceholders(): void
    {
        $prompt = ExtractionService::getExecutionPrompt(
            'factura.pdf',
            'application/pdf'
        );
        $this->assertStringContainsString('factura.pdf', $prompt);
        $this->assertStringContainsString('application/pdf', $prompt);
        $this->assertStringNotContainsString('{{FILE_NAME}}', $prompt);
        $this->assertStringNotContainsString('{{MIME_TYPE}}', $prompt);
        $this->assertStringNotContainsString('{{IMPORT_MODE}}', $prompt);
    }

    public function testGetExecutionPromptUsesUnknownDefaults(): void
    {
        $prompt = ExtractionService::getExecutionPrompt();
        $this->assertStringContainsString('unknown', $prompt);
        $this->assertStringNotContainsString('{{FILE_NAME}}', $prompt);
        $this->assertStringNotContainsString('{{MIME_TYPE}}', $prompt);
    }

    public function testGetExecutionPromptContainsContext(): void
    {
        $prompt = ExtractionService::getExecutionPrompt('test.jpg', 'image/jpeg');
        $this->assertStringContainsString('purchase invoice', $prompt);
        $this->assertStringContainsString('JSON', $prompt);
    }

    public function testCanInstantiate(): void
    {
        $service = new ExtractionService();
        $this->assertInstanceOf(ExtractionService::class, $service);
    }

    public function testGetAvailableProviderNamesReturnsArray(): void
    {
        $service = new ExtractionService();
        $names = $service->getAvailableProviderNames();
        $this->assertIsArray($names);
    }

    public function testExtractFromFileThrowsOnMissingFile(): void
    {
        $service = new ExtractionService();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found');
        $service->extractFromFile('/nonexistent/file.pdf', 'application/pdf');
    }

    public function testDefaultSystemPromptContainsMultiInvoiceInstructions(): void
    {
        $prompt = ExtractionService::getDefaultSystemPrompt();
        $this->assertStringContainsString('MULTIPLE INVOICES', $prompt);
        $this->assertStringContainsString('invoices', $prompt);
        $this->assertStringContainsString('page_range', $prompt);
    }

    public function testDefaultSystemPromptExplainsWrappedLineDescriptions(): void
    {
        $prompt = ExtractionService::getDefaultSystemPrompt();
        $this->assertStringContainsString('wrapped across multiple OCR/text lines', $prompt);
        $this->assertStringContainsString('COCA COLA O CRISTAL', $prompt);
        $this->assertStringContainsString('NOT separate lines', $prompt);
    }

    // ── Manual entry / scan failure fallback (#67) ──────────

    public function testEmptyExtractionPayloadHasExpectedSchema(): void
    {
        $payload = ExtractionService::emptyExtractionPayload('Manual entry');

        $this->assertTrue($payload['_scan_failed']);
        $this->assertIsArray($payload['supplier']);
        $this->assertIsArray($payload['invoice']);
        $this->assertIsArray($payload['lines']);
        $this->assertIsArray($payload['taxes']);
        $this->assertIsArray($payload['confidence']);
        $this->assertSame([], $payload['lines']);
        $this->assertContains('Manual entry', $payload['warnings']);
        $this->assertArrayHasKey('number', $payload['invoice']);
        $this->assertArrayHasKey('tax_id', $payload['supplier']);
        $this->assertSame(0, $payload['confidence']['supplier_tax_id']);
    }

    public function testClassifyExtractionFailureDetectsMissingApiKey(): void
    {
        $result = ExtractionService::classifyExtractionFailure(
            new \RuntimeException('No AI provider configured. Please configure a provider in AiScan settings.')
        );
        $this->assertSame('missing_api_key', $result['code']);
        $this->assertSame('aiscan-scan-failed-no-provider', $result['message_key']);
    }

    public function testClassifyExtractionFailureDetectsUnavailableProvider(): void
    {
        $result = ExtractionService::classifyExtractionFailure(
            new \RuntimeException('Provider "gemini" is not available.')
        );
        $this->assertSame('missing_api_key', $result['code']);
    }

    public function testClassifyExtractionFailureDetectsNetworkError(): void
    {
        $result = ExtractionService::classifyExtractionFailure(
            new \RuntimeException('Gemini request failed: Connection timed out after 120000 ms')
        );
        $this->assertSame('network_error', $result['code']);
        $this->assertSame('aiscan-scan-failed-network', $result['message_key']);
    }

    public function testClassifyExtractionFailureDetectsInvalidJson(): void
    {
        $result = ExtractionService::classifyExtractionFailure(
            new \RuntimeException('Invalid JSON response from AI provider: Syntax error')
        );
        $this->assertSame('invalid_json', $result['code']);
        $this->assertSame('aiscan-scan-failed-invalid-response', $result['message_key']);
    }

    public function testClassifyExtractionFailureDetectsHttpError(): void
    {
        $result = ExtractionService::classifyExtractionFailure(
            new \RuntimeException('Gemini API error (HTTP 429): quota exceeded')
        );
        $this->assertSame('http_error', $result['code']);
        $this->assertSame('aiscan-scan-failed-provider-error', $result['message_key']);
    }

    public function testBuildScanFailedResultIsControlledAndIncludesFlag(): void
    {
        $result = ExtractionService::buildScanFailedResult(
            new \RuntimeException('Gemini request failed: Could not resolve host')
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['scan_failed']);
        $this->assertSame('network_error', $result['scan_failure_code']);
        $this->assertNotEmpty($result['message']);
        $this->assertIsArray($result['data']);
        $this->assertTrue($result['data']['_scan_failed']);
        $this->assertArrayNotHasKey('error', $result);
        // Empty editable shell — no invented invoice fields
        $this->assertNull($result['data']['invoice']['number']);
        $this->assertSame([], $result['data']['lines']);
    }

    public function testBuildScanFailedResultForInvalidJson(): void
    {
        $result = ExtractionService::buildScanFailedResult(
            new \RuntimeException('Invalid JSON response from AI provider: Syntax error')
        );

        $this->assertTrue($result['scan_failed']);
        $this->assertSame('invalid_json', $result['scan_failure_code']);
        $this->assertTrue($result['data']['_scan_failed']);
    }

    public function testExtractFromFileDoesNotCallAiWhenFileMissing(): void
    {
        $service = new ExtractionService();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found');
        $service->extractFromFile('/nonexistent/missing-key.pdf', 'application/pdf');
    }

    public function testGetProviderThrowsWhenNoProviderAvailable(): void
    {
        $service = new ExtractionService();
        // Force a non-existent provider name so isAvailable() path is exercised
        // without depending on host API keys.
        try {
            $service->getProvider('__no_such_provider__');
            $this->fail('Expected RuntimeException when provider is not available');
        } catch (\RuntimeException $e) {
            $classification = ExtractionService::classifyExtractionFailure($e);
            $this->assertSame('missing_api_key', $classification['code']);
            $failed = ExtractionService::buildScanFailedResult($e);
            $this->assertTrue($failed['scan_failed']);
            $this->assertTrue($failed['data']['_scan_failed']);
        }
    }
}
