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

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AiScan\Lib\AiScanSettings;

class GeminiProvider implements ProviderInterface
{
    use ProviderModelTrait;

    private string $apiKey;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = Tools::settings('AiScan', 'gemini_api_key', '');
        $this->model = AiScanSettings::getDefaultModel('gemini');
        $this->timeout = (int) Tools::settings('AiScan', 'request_timeout', 120);
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    public function analyzeDocument(
        string $content,
        string $mimeType,
        string $prompt,
        string $systemPrompt = ''
    ): string {
        $isBinary = in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf']);

        $parts = [];
        if ($isBinary) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data' => $content,
                ],
            ];
        } else {
            $parts[] = ['text' => "Document content:\n" . $content];
        }
        $parts[] = ['text' => $prompt];

        $payload = [
            'contents' => [
                ['parts' => $parts],
            ],
            'generationConfig' => self::buildGenerationConfig($this->model),
        ];

        if (!empty($systemPrompt)) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemPrompt]],
            ];
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . $this->model . ':generateContent?key=' . $this->apiKey;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('Gemini request failed: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException('Gemini API error (HTTP ' . $httpCode . '): ' . $response);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return '';
        }

        return self::extractResponseText($data);
    }

    /**
     * Gemini 3.x rejects temperature and thinkingBudget (HTTP 400 INVALID_ARGUMENT).
     * Use thinkingLevel instead. Gemini 2.5 Flash can disable thinking with budget 0;
     * Gemini 2.5 Pro cannot disable thinking, so that key is omitted.
     *
     * @return array<string, mixed>
     */
    public static function buildGenerationConfig(string $model): array
    {
        $config = [
            'maxOutputTokens' => 32768,
            'responseMimeType' => 'application/json',
        ];

        if (self::isGemini3Family($model)) {
            $config['thinkingConfig'] = ['thinkingLevel' => 'low'];
            return $config;
        }

        $config['temperature'] = 0;

        if (self::isGemini25FlashFamily($model)) {
            $config['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        return $config;
    }

    /**
     * Skip thought parts so Gemini 3 reasoning does not hide the JSON answer.
     *
     * @param array<string, mixed> $data
     */
    public static function extractResponseText(array $data): string
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        if (!is_array($parts)) {
            return '';
        }

        $texts = [];
        foreach ($parts as $part) {
            if (!is_array($part) || !empty($part['thought'])) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text']) && $part['text'] !== '') {
                $texts[] = $part['text'];
            }
        }

        return implode('', $texts);
    }

    public static function isGemini3Family(string $model): bool
    {
        return str_contains(self::normalizeModelId($model), 'gemini-3');
    }

    public static function isGemini25FlashFamily(string $model): bool
    {
        $id = self::normalizeModelId($model);
        return str_contains($id, 'gemini-2.5') && str_contains($id, 'flash');
    }

    public static function normalizeModelId(string $model): string
    {
        $id = strtolower(trim($model));
        if (str_starts_with($id, 'models/')) {
            return substr($id, 7);
        }

        return $id;
    }
}
