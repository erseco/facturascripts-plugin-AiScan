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

namespace FacturaScripts\Plugins\AiScan\Lib\Provider;

/**
 * Builds Chat Completions bodies that stay valid across GPT-5, Grok and
 * classic OpenAI-compatible endpoints.
 */
class ChatCompletionsPayload
{
    public const DEFAULT_MAX_TOKENS = 32768;

    /**
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array<string, mixed>
     */
    public static function build(string $model, array $messages): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'],
        ];

        if (self::usesMaxCompletionTokens($model)) {
            $payload['max_completion_tokens'] = self::DEFAULT_MAX_TOKENS;
        } else {
            $payload['max_tokens'] = self::DEFAULT_MAX_TOKENS;
        }

        if (self::supportsTemperature($model)) {
            $payload['temperature'] = 0;
        }

        return $payload;
    }

    public static function usesMaxCompletionTokens(string $model): bool
    {
        $id = strtolower(trim($model));
        return str_contains($id, 'gpt-5') || (bool) preg_match('/(?:^|\/)o[1-9]/', $id);
    }

    public static function supportsTemperature(string $model): bool
    {
        return !self::usesMaxCompletionTokens($model);
    }
}
