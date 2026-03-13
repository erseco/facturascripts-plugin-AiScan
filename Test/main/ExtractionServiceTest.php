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
}
