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

use FacturaScripts\Plugins\AiScan\Lib\AiScanSettings;
use PHPUnit\Framework\TestCase;

final class AiScanSettingsTest extends TestCase
{
    public function testGetDefaultsReturnsArray(): void
    {
        $defaults = AiScanSettings::getDefaults();
        $this->assertIsArray($defaults);
        $this->assertNotEmpty($defaults);
    }

    public function testDefaultsContainExpectedKeys(): void
    {
        $defaults = AiScanSettings::getDefaults();
        $this->assertArrayHasKey('enabled', $defaults);
        $this->assertArrayHasKey('default_provider', $defaults);
        $this->assertArrayHasKey('max_upload_size_mb', $defaults);
        $this->assertArrayHasKey('allowed_extensions', $defaults);
        $this->assertArrayHasKey('debug_mode', $defaults);
        $this->assertArrayHasKey('request_timeout', $defaults);
        $this->assertArrayHasKey('openai_model', $defaults);
        $this->assertArrayHasKey('openai_base_url', $defaults);
        $this->assertArrayHasKey('gemini_model', $defaults);
        $this->assertArrayHasKey('mistral_model', $defaults);
    }

    public function testDefaultProviderIsOpenai(): void
    {
        $defaults = AiScanSettings::getDefaults();
        $this->assertSame('openai', $defaults['default_provider']);
    }

    public function testDefaultExtensionsIncludesPdf(): void
    {
        $defaults = AiScanSettings::getDefaults();
        $this->assertStringContainsString('pdf', $defaults['allowed_extensions']);
    }

    public function testDefaultEnabledIsTrue(): void
    {
        $defaults = AiScanSettings::getDefaults();
        $this->assertTrue($defaults['enabled']);
    }

    public function testDefaultTimeoutIs120(): void
    {
        $defaults = AiScanSettings::getDefaults();
        $this->assertSame(120, $defaults['request_timeout']);
    }

    public function testDefaultMaxUploadSizeIs10(): void
    {
        $defaults = AiScanSettings::getDefaults();
        $this->assertSame(10, $defaults['max_upload_size_mb']);
    }

    public function testIsEnabledReturnsBool(): void
    {
        $result = AiScanSettings::isEnabled();
        $this->assertIsBool($result);
    }

    public function testGetDefaultProviderReturnsString(): void
    {
        $result = AiScanSettings::getDefaultProvider();
        $this->assertIsString($result);
    }

    public function testGetMaxUploadSizeMbReturnsInt(): void
    {
        $result = AiScanSettings::getMaxUploadSizeMb();
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }

    public function testGetAllowedExtensionsReturnsArray(): void
    {
        $result = AiScanSettings::getAllowedExtensions();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testGetRequestTimeoutReturnsAtLeast10(): void
    {
        $result = AiScanSettings::getRequestTimeout();
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(10, $result);
    }

    public function testIsDebugModeReturnsBool(): void
    {
        $result = AiScanSettings::isDebugMode();
        $this->assertIsBool($result);
    }
}
