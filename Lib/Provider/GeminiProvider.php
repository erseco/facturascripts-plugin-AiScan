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

class GeminiProvider implements ProviderInterface
{
    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = Tools::settings('AiScan', 'gemini_api_key', '');
        $this->model = Tools::settings('AiScan', 'gemini_model', 'gemini-2.5-flash');
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
            'generationConfig' => [
                'temperature' => 0,
                'maxOutputTokens' => 4096,
                'responseMimeType' => 'application/json',
            ],
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
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }
}
