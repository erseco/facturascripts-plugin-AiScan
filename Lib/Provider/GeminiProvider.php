<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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
        $this->model = Tools::settings('AiScan', 'gemini_model', 'gemini-1.5-flash');
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

    public function analyzeDocument(string $content, string $mimeType, string $prompt): string
    {
        $isImage = in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

        $parts = [];
        if ($isImage) {
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

        $payload = json_encode([
            'contents' => [
                ['parts' => $parts],
            ],
            'generationConfig' => [
                'temperature' => 0,
                'maxOutputTokens' => 4096,
                'responseMimeType' => 'application/json',
            ],
        ]);

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . $this->model . ':generateContent?key=' . $this->apiKey;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
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
