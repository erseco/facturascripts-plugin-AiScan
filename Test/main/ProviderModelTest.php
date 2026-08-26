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
use FacturaScripts\Plugins\AiScan\Lib\Provider\ChatCompletionsPayload;
use FacturaScripts\Plugins\AiScan\Lib\Provider\GeminiProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\GrokProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\MistralProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\MockProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\OpenAICompatibleProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\OpenAIProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\ProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Multi-model support (#89): every provider starts on the default model of its
 * configured list and accepts another one for a single analysis.
 */
final class ProviderModelTest extends TestCase
{
    /**
     * @return array<int, ProviderInterface>
     */
    private function providers(): array
    {
        return [
            new OpenAIProvider(),
            new GeminiProvider(),
            new MistralProvider(),
            new GrokProvider(),
            new OpenAICompatibleProvider(),
            new MockProvider(),
        ];
    }

    public function testProvidersStartOnTheDefaultModelOfTheirList(): void
    {
        foreach ($this->providers() as $provider) {
            $this->assertSame(
                AiScanSettings::getDefaultModel($provider->getName()),
                $provider->getModel(),
                $provider->getName() . ' should start on its default model'
            );
        }
    }

    public function testEveryProviderAcceptsAnotherModel(): void
    {
        foreach ($this->providers() as $provider) {
            $provider->setModel('another-model');
            $this->assertSame('another-model', $provider->getModel());
        }
    }

    public function testSelectedModelReachesTheChatCompletionsPayload(): void
    {
        $provider = new OpenAIProvider();
        $provider->setModel('gpt-5.2');

        $payload = ChatCompletionsPayload::build($provider->getModel(), []);

        $this->assertSame('gpt-5.2', $payload['model']);
    }

    public function testSelectedModelDrivesGeminiRequestShaping(): void
    {
        $provider = new GeminiProvider();

        $provider->setModel('gemini-2.5-flash-lite');
        $this->assertArrayHasKey('temperature', GeminiProvider::buildGenerationConfig($provider->getModel()));

        $provider->setModel('gemini-3.6-flash');
        $this->assertArrayNotHasKey('temperature', GeminiProvider::buildGenerationConfig($provider->getModel()));
    }
}
