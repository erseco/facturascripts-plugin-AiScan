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

use FacturaScripts\Plugins\AiScan\Lib\Provider\GeminiProvider;
use PHPUnit\Framework\TestCase;

final class GeminiProviderTest extends TestCase
{
    public function testGemini3FlashOmitsTemperatureAndThinkingBudget(): void
    {
        $config = GeminiProvider::buildGenerationConfig('gemini-3.6-flash');

        $this->assertArrayNotHasKey('temperature', $config);
        $this->assertSame('application/json', $config['responseMimeType']);
        $this->assertSame(32768, $config['maxOutputTokens']);
        $this->assertSame('low', $config['thinkingConfig']['thinkingLevel']);
        $this->assertArrayNotHasKey('thinkingBudget', $config['thinkingConfig']);
    }

    public function testGemini31ProUsesThinkingLevelNotBudget(): void
    {
        $config = GeminiProvider::buildGenerationConfig('gemini-3.1-pro');

        $this->assertArrayNotHasKey('temperature', $config);
        $this->assertSame('low', $config['thinkingConfig']['thinkingLevel']);
        $this->assertArrayNotHasKey('thinkingBudget', $config['thinkingConfig']);
    }

    public function testGemini25FlashKeepsDisabledThinkingBudget(): void
    {
        $config = GeminiProvider::buildGenerationConfig('gemini-2.5-flash-lite');

        $this->assertSame(0, $config['temperature']);
        $this->assertSame(0, $config['thinkingConfig']['thinkingBudget']);
        $this->assertArrayNotHasKey('thinkingLevel', $config['thinkingConfig']);
        $this->assertSame('application/json', $config['responseMimeType']);
    }

    public function testModelsPrefixDoesNotHideGemini3Family(): void
    {
        $config = GeminiProvider::buildGenerationConfig('models/gemini-3.6-flash');

        $this->assertArrayNotHasKey('temperature', $config);
        $this->assertSame('low', $config['thinkingConfig']['thinkingLevel']);
    }

    public function testGemini25ProDoesNotDisableThinking(): void
    {
        $config = GeminiProvider::buildGenerationConfig('gemini-2.5-pro');

        $this->assertSame(0, $config['temperature']);
        $this->assertArrayNotHasKey('thinkingConfig', $config);
    }

    public function testExtractResponseTextSkipsThoughtParts(): void
    {
        $data = [
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['thought' => true, 'text' => 'internal reasoning'],
                        ['text' => '{"invoice":{"number":"1"}}'],
                    ],
                ],
            ]],
        ];

        $this->assertSame(
            '{"invoice":{"number":"1"}}',
            GeminiProvider::extractResponseText($data)
        );
    }

    public function testExtractResponseTextFallsBackToFirstTextPart(): void
    {
        $data = [
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => '{"ok":true}'],
                    ],
                ],
            ]],
        ];

        $this->assertSame('{"ok":true}', GeminiProvider::extractResponseText($data));
    }
}
