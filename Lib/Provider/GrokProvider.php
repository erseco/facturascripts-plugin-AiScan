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

class GrokProvider implements ProviderInterface
{
    use ProviderModelTrait;

    private const BASE_URL = 'https://api.x.ai/v1';

    private string $apiKey;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = Tools::settings('AiScan', 'grok_api_key', '');
        $this->model = AiScanSettings::getDefaultModel('grok');
        $this->timeout = (int) Tools::settings('AiScan', 'request_timeout', 120);
    }

    public function getName(): string
    {
        return 'grok';
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
            $messages[] = [
                'role' => 'user',
                'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mimeType . ';base64,' . $content]],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ];
        } else {
            $messages[] = [
                'role' => 'user',
                'content' => $prompt . "\n\nDocument content:\n" . $content,
            ];
        }

        $payload = json_encode(ChatCompletionsPayload::build($this->model, $messages));

        $ch = curl_init(self::BASE_URL . '/chat/completions');
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
            throw new \RuntimeException('Grok request failed: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException('Grok API error (HTTP ' . $httpCode . '): ' . $response);
        }

        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? '';
    }
}
