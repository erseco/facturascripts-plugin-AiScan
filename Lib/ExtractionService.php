<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Ernesto Serrano <ernesto@erseco.es>
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

namespace FacturaScripts\Plugins\AiScan\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AiScan\Lib\Provider\GeminiProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\MistralProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\OpenAICompatibleProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\OpenAIProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\ProviderInterface;

class ExtractionService
{
    private SchemaValidator $validator;

    private const EXTRACTION_PROMPT = <<<'PROMPT'
You are an expert invoice data extractor. Analyze the provided invoice document and extract all relevant information.

Return ONLY a valid JSON object with the following structure (no markdown, no explanation, just the JSON):
{
  "supplier": {
    "name": "",
    "tax_id": "",
    "email": "",
    "phone": "",
    "address": ""
  },
  "invoice": {
    "number": "",
    "issue_date": "YYYY-MM-DD",
    "due_date": "YYYY-MM-DD or null",
    "currency": "EUR",
    "subtotal": 0.00,
    "tax_amount": 0.00,
    "total": 0.00,
    "summary": "",
    "notes": ""
  },
  "taxes": [
    {
      "name": "VAT/IVA",
      "rate": 21.0,
      "base": 0.00,
      "amount": 0.00
    }
  ],
  "lines": [
    {
      "description": "",
      "quantity": 1.0,
      "unit_price": 0.00,
      "discount": 0.0,
      "tax_rate": 21.0,
      "line_total": 0.00,
      "sku": ""
    }
  ],
  "meta": {
    "language": "en",
    "confidence": 0.9
  }
}

Rules:
- Dates must be in YYYY-MM-DD format
- All monetary values must be numbers (not strings)
- If a field is not found, use null for optional fields or 0 for numeric fields
- Extract all line items if present
- Identify the currency from the document (default EUR)
- Extract tax rates per line if possible
PROMPT;

    public function __construct()
    {
        $this->validator = new SchemaValidator();
    }

    public function getProvider(): ProviderInterface
    {
        $defaultProvider = Tools::settings('AiScan', 'default_provider', 'openai');

        $providers = [
            'openai' => new OpenAIProvider(),
            'gemini' => new GeminiProvider(),
            'mistral' => new MistralProvider(),
            'openai-compatible' => new OpenAICompatibleProvider(),
        ];

        if (isset($providers[$defaultProvider]) && $providers[$defaultProvider]->isAvailable()) {
            return $providers[$defaultProvider];
        }

        foreach ($providers as $provider) {
            if ($provider->isAvailable()) {
                return $provider;
            }
        }

        throw new \RuntimeException(
            'No AI provider configured. Please configure a provider in AiScan settings.'
        );
    }

    public function extractFromFile(string $filePath, string $mimeType): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException('File not found: ' . $filePath);
        }

        $provider = $this->getProvider();

        if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) {
            $content = base64_encode(file_get_contents($filePath));
        } elseif ($mimeType === 'application/pdf') {
            $content = $this->extractPdfText($filePath);
            if (empty(trim($content))) {
                $content = base64_encode(file_get_contents($filePath));
            }
        } else {
            $content = file_get_contents($filePath);
        }

        $rawJson = $provider->analyzeDocument($content, $mimeType, self::EXTRACTION_PROMPT);

        $rawJson = preg_replace('/^```(?:json)?\n?/m', '', $rawJson);
        $rawJson = preg_replace('/\n?```$/m', '', $rawJson);
        $rawJson = trim($rawJson);

        $data = json_decode($rawJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Invalid JSON response from AI provider: ' . json_last_error_msg()
            );
        }

        $data = $this->validator->normalize($data);
        $errors = $this->validator->validate($data);

        $data['_validation_errors'] = $errors;
        $data['_provider'] = $provider->getName();

        return $data;
    }

    private function extractPdfText(string $filePath): string
    {
        // Check for pdftotext in known locations to avoid shell_exec for availability check
        $pdftotextBin = null;
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $candidate) {
            if (is_executable($candidate)) {
                $pdftotextBin = $candidate;
                break;
            }
        }

        if ($pdftotextBin === null) {
            return '';
        }

        // Validate file is within expected temp directory to prevent path traversal
        $realPath = realpath($filePath);
        $expectedDir = realpath(FS_FOLDER . '/MyFiles/aiscan_tmp');
        if ($realPath === false || $expectedDir === false || strpos($realPath, $expectedDir) !== 0) {
            return '';
        }

        $output = [];
        $returnCode = 0;
        exec($pdftotextBin . ' ' . escapeshellarg($realPath) . ' - 2>/dev/null', $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        return '';
    }
}
