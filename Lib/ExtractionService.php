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

    private const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
You are an expert invoice and receipt extraction engine for purchase invoices in an ERP.

Your job is to analyze one supplier invoice, receipt, or purchase document from:
- OCR text
- extracted PDF text
- one or more page images
- optional user hints

You must extract structured data for a PURCHASE invoice only.

Core rules:
1. Return ONLY valid JSON.
2. Do not wrap the response in markdown.
3. Do not include explanations outside the JSON.
4. Never invent values. If a value is not present or not reliable, use null.
5. Prefer exact extraction over guessing.
6. Keep the original language of descriptions and supplier names.
7. Normalize numbers using dot decimals, for example 1234.56
8. Normalize dates to YYYY-MM-DD when confidence is sufficient. Otherwise keep null and add a warning.
9. If there are multiple candidates for a field, choose the most likely one and include the ambiguity in warnings.
10. If the document is not clearly an invoice/receipt/purchase document,
    set document_type accordingly and add a warning.
11. Distinguish between extracted facts and weak inferences.
12. Do not create catalog products or suppliers. Only extract and suggest.
13. If line items are not visible, return an empty array for lines instead of inventing them.
14. Validate arithmetic consistency:
    - subtotal + taxes should approximately match total
    - if not, keep extracted values but add a warning
15. Detect the supplier, not the customer:
    - supplier/vendor/issuer = the company that issued the invoice
    - buyer/customer = the company receiving the invoice
16. For purchase invoice workflows, prioritize:
    - supplier identity
    - supplier tax id
    - invoice number
    - issue date
    - due date
    - currency
    - subtotal
    - taxes
    - total
    - line items
17. If the document contains several pages, combine evidence across all pages.
18. If the same field appears multiple times, prefer:
    - explicit invoice labels
    - totals section
    - header metadata
    - then OCR body text
19. For receipts, line items may be short retail items. For invoices, line items may be services or products.
20. Confidence must be a number from 0 to 1.

Output schema:

{
  "document_type": "invoice | receipt | proforma | credit_note | unknown",
  "supplier": {
    "name": "string|null",
    "tax_id": "string|null",
    "email": "string|null",
    "phone": "string|null",
    "website": "string|null",
    "address": "string|null"
  },
  "customer": {
    "name": "string|null",
    "tax_id": "string|null",
    "address": "string|null"
  },
  "invoice": {
    "number": "string|null",
    "issue_date": "YYYY-MM-DD|null",
    "due_date": "YYYY-MM-DD|null",
    "currency": "ISO_4217|null",
    "subtotal": "number|null",
    "tax_amount": "number|null",
    "withholding_amount": "number|null",
    "total": "number|null",
    "summary": "string|null",
    "payment_terms": "string|null"
  },
  "taxes": [
    {
      "name": "string|null",
      "rate": "number|null",
      "base": "number|null",
      "amount": "number|null"
    }
  ],
  "lines": [
    {
      "description": "string",
      "quantity": "number|null",
      "unit_price": "number|null",
      "discount": "number|null",
      "tax_rate": "number|null",
      "line_total": "number|null",
      "sku": "string|null"
    }
  ],
  "confidence": {
    "supplier_name": 0,
    "supplier_tax_id": 0,
    "invoice_number": 0,
    "issue_date": 0,
    "total": 0,
    "lines": 0
  },
  "warnings": []
}

Field-specific rules:
- supplier.name: the issuer of the invoice, not the buyer
- supplier.tax_id: preserve original formatting if present
- invoice.number: do not confuse order number, delivery note, reference, or customer id with invoice number
- issue_date: prefer invoice/emission/date labels over service period dates
- due_date: only if explicitly present
- currency: infer from symbol only when clear
- subtotal: prefer net amount before taxes
- tax_amount: sum of all taxes if explicit or safely computable
- total: final payable amount
- summary: concise one-line description of what the invoice is for, based only on document evidence
- taxes: include each visible tax breakdown
- lines: only include lines that are actually visible or clearly extracted

Arithmetic rules:
- If subtotal, tax_amount, and total are present and inconsistent by more
  than a small rounding tolerance, add a warning.
- Do not modify extracted numbers just to make them fit.

Additional regional rules for Spain/EU invoices:
- Recognize VAT identifiers such as NIF, CIF, DNI, VAT, IVA, CIF/NIF, VAT ID
- Common tax labels may include IVA, IGIC, IRPF, VAT, TAX
- "Base imponible" usually maps to subtotal
- "Cuota" or "IVA" usually maps to tax amount
- "Total factura", "Importe total", "Total", "Total a pagar" usually map to total
- "Fecha de expedición" or "Fecha factura" usually map to issue_date
- "Vencimiento" maps to due_date
- "Proveedor", issuer header, company header, or footer legal block may identify supplier
- Do not confuse the buyer tax id with the supplier tax id
- If IRPF or withholding is present, store it in withholding_amount
- If IGIC appears, include it in taxes exactly as shown
PROMPT;

    private const DEFAULT_EXECUTION_PROMPT = <<<'PROMPT'
Analyze this purchase invoice document and extract structured invoice data.

Context:
- ERP module: purchase invoices
- Goal: create or complete a supplier purchase invoice
- File name: {{FILE_NAME}}
- MIME type: {{MIME_TYPE}}
- Import mode: {{IMPORT_MODE}}

Important:
- Prefer exact extraction from the document.
- If a field is missing or uncertain, use null.
- Return ONLY valid JSON matching the schema from the system prompt.
- Do not return markdown.
PROMPT;

    private const MODE_LINES_HINT = <<<'PROMPT'

Import mode is "lines": Extract every individual line item visible in the document.
Prioritize real line extraction. If line items are not clearly visible, return an empty lines array.
Do not invent or fabricate line items.
PROMPT;

    private const MODE_TOTAL_HINT = <<<'PROMPT'

Import mode is "total": Focus on accurate invoice-level totals (subtotal, tax, total).
Do not try to extract individual line items. Return an empty lines array.
The operator will create a single line from the totals.
PROMPT;

    public function __construct()
    {
        $this->validator = new SchemaValidator();
    }

    public static function getSystemPrompt(): string
    {
        $custom = Tools::settings('AiScan', 'extraction_prompt', '');
        return !empty(trim($custom)) ? $custom : self::DEFAULT_SYSTEM_PROMPT;
    }

    public static function getDefaultSystemPrompt(): string
    {
        return self::DEFAULT_SYSTEM_PROMPT;
    }

    public static function getExecutionPrompt(
        string $fileName = '',
        string $mimeType = '',
        string $importMode = 'lines',
        string $historicalContext = ''
    ): string {
        $prompt = self::DEFAULT_EXECUTION_PROMPT;
        $prompt = str_replace('{{FILE_NAME}}', $fileName ?: 'unknown', $prompt);
        $prompt = str_replace('{{MIME_TYPE}}', $mimeType ?: 'unknown', $prompt);
        $prompt = str_replace('{{IMPORT_MODE}}', $importMode === 'total' ? 'total' : 'lines', $prompt);

        $prompt .= $importMode === 'total' ? self::MODE_TOTAL_HINT : self::MODE_LINES_HINT;

        if (!empty(trim($historicalContext))) {
            $prompt .= "\n\n" . $historicalContext;
        }

        return $prompt;
    }

    public function getProvider(?string $providerName = null): ProviderInterface
    {
        $defaultProvider = $providerName ?: Tools::settings('AiScan', 'default_provider', 'openai');

        $providers = $this->getAllProviders();

        if (isset($providers[$defaultProvider]) && $providers[$defaultProvider]->isAvailable()) {
            return $providers[$defaultProvider];
        }

        if ($providerName) {
            throw new \RuntimeException('Provider "' . $providerName . '" is not available.');
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

    public function getAvailableProviderNames(): array
    {
        $names = [];
        foreach ($this->getAllProviders() as $key => $provider) {
            if ($provider->isAvailable()) {
                $names[] = $key;
            }
        }
        return $names;
    }

    private function getAllProviders(): array
    {
        return [
            'openai' => new OpenAIProvider(),
            'gemini' => new GeminiProvider(),
            'mistral' => new MistralProvider(),
            'openai-compatible' => new OpenAICompatibleProvider(),
        ];
    }

    public function extractFromFile(
        string $filePath,
        string $mimeType,
        ?string $providerName = null,
        string $importMode = 'lines',
        string $historicalContext = ''
    ): array {
        if (!file_exists($filePath)) {
            throw new \RuntimeException('File not found: ' . $filePath);
        }

        $provider = $this->getProvider($providerName);
        $fileName = basename($filePath);

        $actualMimeType = $mimeType;

        if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) {
            $content = base64_encode(file_get_contents($filePath));
        } elseif ($mimeType === 'application/pdf') {
            $pdfText = self::extractPdfText($filePath);
            if (!empty(trim($pdfText))) {
                $content = $pdfText;
                $actualMimeType = 'text/plain';
            } else {
                $content = base64_encode(file_get_contents($filePath));
            }
        } else {
            $content = file_get_contents($filePath);
        }

        $systemPrompt = self::getSystemPrompt();
        $executionPrompt = self::getExecutionPrompt($fileName, $mimeType, $importMode, $historicalContext);

        $rawJson = $provider->analyzeDocument($content, $actualMimeType, $executionPrompt, $systemPrompt);

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

    public static function extractPdfText(string $filePath): string
    {
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
