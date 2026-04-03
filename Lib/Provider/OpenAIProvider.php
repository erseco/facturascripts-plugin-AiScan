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

class OpenAIProvider implements ProviderInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = Tools::settings('AiScan', 'openai_api_key', '');
        $this->model = Tools::settings('AiScan', 'openai_model', 'gpt-5-nano');
        $this->baseUrl = Tools::settings('AiScan', 'openai_base_url', 'https://api.openai.com/v1');
        $this->timeout = (int) Tools::settings('AiScan', 'request_timeout', 120);
    }

    public function getName(): string
    {
        return 'openai';
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

        $messages = [];

        if (!empty($systemPrompt)) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        if ($isBinary) {
            $contentParts = [];
            if ($mimeType === 'application/pdf') {
                $contentParts[] = [
                    'type' => 'file',
                    'file' => [
                        'filename' => 'invoice.pdf',
                        'file_data' => 'data:application/pdf;base64,' . $content,
                    ],
                ];
            } else {
                $contentParts[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => 'data:' . $mimeType . ';base64,' . $content,
                    ],
                ];
            }
            $contentParts[] = ['type' => 'text', 'text' => $prompt];

            $messages[] = [
                'role' => 'user',
                'content' => $contentParts,
            ];
        } else {
            $messages[] = [
                'role' => 'user',
                'content' => $prompt . "\n\nDocument content:\n" . $content,
            ];
        }

        $payload = json_encode([
            'model' => $this->model,
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'],
            'max_completion_tokens' => 4096,
        ]);

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('OpenAI request failed: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException('OpenAI API error (HTTP ' . $httpCode . '): ' . $response);
        }

        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? '';
    }
}
