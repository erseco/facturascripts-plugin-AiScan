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

use FacturaScripts\Plugins\AiScan\Lib\Provider\ChatCompletionsPayload;
use PHPUnit\Framework\TestCase;

final class ChatCompletionsPayloadTest extends TestCase
{
    public function testGpt5OmitsTemperatureAndUsesMaxCompletionTokens(): void
    {
        $payload = ChatCompletionsPayload::build('gpt-5.6', [
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertSame('gpt-5.6', $payload['model']);
        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertSame(32768, $payload['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $payload);
        $this->assertSame('json_object', $payload['response_format']['type']);
    }

    public function testGrokUsesMaxTokensAndTemperature(): void
    {
        $payload = ChatCompletionsPayload::build('grok-4.5', [
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertSame('grok-4.5', $payload['model']);
        $this->assertSame(0, $payload['temperature']);
        $this->assertSame(32768, $payload['max_tokens']);
        $this->assertArrayNotHasKey('max_completion_tokens', $payload);
    }

    public function testGpt5NanoMatchesCurrentOpenAIDefaults(): void
    {
        $payload = ChatCompletionsPayload::build('gpt-5-nano', [
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertSame(32768, $payload['max_completion_tokens']);
    }

    public function testOpenRouterGpt5SlugUsesMaxCompletionTokens(): void
    {
        $payload = ChatCompletionsPayload::build('openai/gpt-5.6', [
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertSame(32768, $payload['max_completion_tokens']);
    }

    public function testGpt6LunaOmitsTemperatureAndUsesMaxCompletionTokens(): void
    {
        $payload = ChatCompletionsPayload::build('gpt-6-luna', [
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertSame('gpt-6-luna', $payload['model']);
        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertSame(32768, $payload['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $payload);
    }

    public function testOpenRouterGpt6LunaUsesMaxCompletionTokens(): void
    {
        $payload = ChatCompletionsPayload::build('openai/gpt-6-luna', [
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertSame('openai/gpt-6-luna', $payload['model']);
        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertSame(32768, $payload['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $payload);
    }

    public function testGpt6WithSpaceOmitsTemperatureAndUsesMaxCompletionTokens(): void
    {
        $payload = ChatCompletionsPayload::build('gpt 6 luna', [
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertSame(32768, $payload['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $payload);
    }

    public function testO3MiniOmitsTemperatureAndUsesMaxCompletionTokens(): void
    {
        $payload = ChatCompletionsPayload::build('o3-mini', [
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertSame('o3-mini', $payload['model']);
        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertSame(32768, $payload['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $payload);
    }

    public function testPrefixedGpt6AndChatGpt6UseMaxCompletionTokens(): void
    {
        $payloadAzure = ChatCompletionsPayload::build('azure-gpt-6', [
            ['role' => 'user', 'content' => 'hi'],
        ]);
        $this->assertArrayNotHasKey('temperature', $payloadAzure);
        $this->assertSame(32768, $payloadAzure['max_completion_tokens']);

        $payloadChatGpt = ChatCompletionsPayload::build('chatgpt-6', [
            ['role' => 'user', 'content' => 'hi'],
        ]);
        $this->assertArrayNotHasKey('temperature', $payloadChatGpt);
        $this->assertSame(32768, $payloadChatGpt['max_completion_tokens']);
    }

    public function testGpt4oKeepsMaxTokensAndTemperature(): void
    {
        $payload = ChatCompletionsPayload::build('gpt-4o', [
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertSame('gpt-4o', $payload['model']);
        $this->assertSame(0, $payload['temperature']);
        $this->assertSame(32768, $payload['max_tokens']);
        $this->assertArrayNotHasKey('max_completion_tokens', $payload);
    }
}
