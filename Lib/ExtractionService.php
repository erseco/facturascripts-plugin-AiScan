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
13. Warnings must be useful to the end user, not implementation details.
    - NEVER reference JSON field names (withholding_amount, issue_date, tax_amount, etc.).
    - NEVER explain internal extraction logic or sign conversions.
    - NEVER warn about arithmetic that is actually correct (difference = 0).
    - Use human-readable terms in the app language (retenciones, fecha, impuestos).
    - Only warn about REAL problems: values that don't add up, ambiguous data, missing info.
14. If line items are not visible, return an empty array for lines instead of inventing them.
15. Validate arithmetic consistency:
    - subtotal + taxes should approximately match total
    - if not, keep extracted values but add a warning
16. Detect the supplier, not the customer:
    - supplier/vendor/issuer = the company that issued the invoice
    - buyer/customer = the company receiving the invoice
17. For purchase invoice workflows, prioritize:
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
18. If the document contains several pages, combine evidence across all pages.
19. If the same field appears multiple times, prefer:
    - explicit invoice labels
    - totals section
    - header metadata
    - then OCR body text
20. For receipts, line items may be short retail items. For invoices, line items may be services or products.
21. Confidence must be a number from 0 to 1.

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
      "descripcion": "string (line description)",
      "cantidad": "number (quantity, default 1)",
      "pvpunitario": "number (unit price before tax, REQUIRED)",
      "dtopor": "number (discount %, default 0)",
      "dtopor2": "number (second discount %, default 0)",
      "codimpuesto": "string|null (tax code from available tax types list, e.g. IGIC7, IVA21)",
      "iva": "number (tax rate %, e.g. 7, 21)",
      "recargo": "number (surcharge rate %, default 0)",
      "irpf": "number (withholding rate %, default 0)",
      "excepcioniva": "string|null (tax exception code if applicable)",
      "suplido": "boolean (reimbursement, default false)",
      "referencia": "string|null (product reference/SKU if identifiable)"
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
- subtotal: EXTRACT from the document totals section (e.g. "Base Imponible", "TOTAL SIN IGIC").
  Do NOT compute from lines — read the actual value shown in the document.
- tax_amount: EXTRACT from the document totals section (e.g. "IGIC", "IVA", "Cuota").
  Do NOT compute from lines — read the actual value shown in the document.
- total: EXTRACT from the document totals section (e.g. "TOTAL FACTURA", "TOTAL IMP").
  Do NOT compute from lines — read the actual value shown in the document.
- summary: concise one-line description of what the invoice is for, based only on document evidence
- taxes: include each visible tax breakdown
- lines: only include lines that are actually visible or clearly extracted.
  For hierarchical/grouped invoices (e.g. lines grouped by customer/category with subtotals),
  extract ONLY the top-level summary lines (one per group with the group total),
  NOT the individual sub-items. The sub-items are detail — the group total is the line.
  Example: "Client A: 4.48€" with sub-items below → one line: descripcion="Client A", pvpunitario=4.48
- lines.cantidad: REQUIRED. Default to 1 if not visible but a line exists.
- lines.pvpunitario: REQUIRED. Unit price BEFORE discount and BEFORE tax.
  Use the "Precio", "Precio Unidad", "Precio Unit.", "Price" column — NOT "Importe" or "Total".
  "Importe" and "Total" columns show the price AFTER discount — do NOT use them as pvpunitario.
  If the document only has "Base Imponible" per line and no "Precio" column, use that as pvpunitario.
  If cantidad > 1, divide the base amount by cantidad.
  Never return 0 or null if the line has a visible monetary amount.
  NEVER confuse "Dto" / "Descuento" / "Discount" columns with the unit price.
  MANDATORY VERIFICATION for each line:
  1. Read the line "Total" or "Importe" column value.
  2. Compute: cantidad * pvpunitario * (1 - dtopor/100).
  3. If result does NOT match the line total, pvpunitario is WRONG — recalculate it.
  When table columns are ambiguous, derive pvpunitario FROM the line total:
  pvpunitario = line_total / cantidad (when dtopor is 0).
  Example: Qty=75, Total=75 → pvpunitario = 75/75 = 1 (NOT 0.25 or any other number).
  Also verify: sum of all line totals must approximately equal invoice subtotal.
- lines.dtopor: discount percentage. Default 0.
  The "Dto" / "Descuento" column is often a percentage discount.
  If the document shows a "Dto" column, map it to dtopor (not to pvpunitario).
- lines.codimpuesto: tax type code from the available tax types list (e.g. IGIC7, IVA21).
  Match the visible tax rate to the closest code from the list.
- lines.iva: the tax rate percentage matching codimpuesto.
- lines.irpf: withholding rate percentage. Default 0.
  CRITICAL — NEVER compute irpf by dividing total withholding by total subtotal.
  Instead, read the RETENCIONES table in the document. It shows which base amounts
  have retention and at what rate. Match each line to its retention base:
  - If a line's tax base belongs to "Retención X%", set irpf = X.
  - If a line's tax base belongs to "Sin retención" or 0%, set irpf = 0.
  Example: RETENCIONES shows "Sin retención: 180€, Retención 15%: 1.000€"
  → lines totaling 1.000€ get irpf=15, lines totaling 180€ get irpf=0.
  The irpf rate MUST match one of the available withholding types in the system.
- lines.recargo: surcharge/equivalence charge percentage. Default 0.
- lines.suplido: true only if the line is a reimbursement (suplido). Default false.
- withholding_amount: EXTRACT the total retenciones amount directly from the document.
  Must always be a POSITIVE number (absolute value).
  If the document shows "Retenciones: -392,00 €", store 392.00 not -392.00.
  Do NOT compute this from lines — read it from the document's retenciones summary.
  Retenciones/withholding reduces the total: total = subtotal + tax - withholding.
  If retenciones is 0,00 €, set withholding_amount to 0.

Arithmetic rules:
- If subtotal, tax_amount, and total are present and inconsistent by more
  than a small rounding tolerance, add a warning.
- Do not modify extracted numbers just to make them fit.
- FINAL CHECK: if sum of line totals does not match the extracted subtotal,
  your line extraction has errors. Review and fix pvpunitario values.

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
- IRPF / I.R.P.F. / Retenciones: ALWAYS check for withholding sections in the document.
  Common labels: "I.R.P.F.", "IRPF", "Retenciones", "Retención".
  The withholding amount REDUCES the total: total = subtotal + tax - withholding.
  If IRPF is present, store the amount in withholding_amount AND set irpf on each line.
  The TOTAL shown in the document is ALWAYS the final amount AFTER deducting IRPF.
- If IGIC appears, include it in taxes exactly as shown
- If the document shows a single tax rate for the whole invoice (e.g. "TIPO IGIC: 7,00"),
  apply that rate to ALL lines including ancillary charges like "Canon Digital", "LPI", fees.
  Set codimpuesto AND iva on every line (e.g. codimpuesto="IGIC7", iva=7).
  Only set iva=0 on a line if the tax breakdown explicitly shows a 0% base for it.
- ALWAYS set codimpuesto on every line. Match the tax rate to the available tax types list.
  For example: IGIC 7% → codimpuesto="IGIC7", IVA 21% → codimpuesto="IVA21".
PROMPT;

    private const DEFAULT_EXECUTION_PROMPT = <<<'PROMPT'
Analyze this purchase invoice document and extract structured invoice data.

Context:
- ERP module: purchase invoices
- Goal: create or complete a supplier purchase invoice
- Today's date: {{TODAY}}
- File name: {{FILE_NAME}}
- MIME type: {{MIME_TYPE}}
- Import mode: {{IMPORT_MODE}}

Important:
- Prefer exact extraction from the document.
- If a field is missing or uncertain, use null.
- Return ONLY valid JSON matching the schema from the system prompt.
- Do not return markdown.
- MUST return all text fields (summary, warnings, descriptions) in {{LANG}}.
  If the app language is Spanish, write summaries and warnings in Spanish.
  If the app language is English, write them in English.
PROMPT;

    private const MODE_LINES_HINT = <<<'PROMPT'

Import mode is "lines": Extract every individual line item visible in the document.
Prioritize real line extraction. If line items are not clearly visible, return an empty lines array.
Do not invent or fabricate line items.
PROMPT;

    private const MODE_TOTAL_HINT = <<<'PROMPT'

Import mode is "total": Focus on accurate invoice-level totals (subtotal, tax, total).
Do not try to extract individual line items. Return an empty lines array.
IMPORTANT: Always extract the "taxes" array with every distinct tax rate present in the invoice.
Each entry must have: name, rate, base (taxable amount for that rate), and amount (tax amount).
Examples:
- An invoice with IVA 21% on 1000 and IVA 0% on 500:
  taxes: [{"name":"IVA","rate":21,"base":1000,"amount":210},{"name":"IVA","rate":0,"base":500,"amount":0}]
- A Canary Islands invoice with IGIC 7% and exempt:
  taxes: [{"name":"IGIC","rate":7,"base":800,"amount":56},{"name":"IGIC","rate":0,"base":200,"amount":0}]
The system will create one invoice line per tax rate from this breakdown.
IMPORTANT: Always extract withholding_amount from the RETENCIONES section if present.
The system will compute the IRPF rate per line from the withholding total.
PROMPT;

    public function __construct()
    {
        $this->validator = new SchemaValidator();
    }

    public static function getSystemPrompt(): string
    {
        $prompt = self::DEFAULT_SYSTEM_PROMPT;
        $custom = trim(Tools::settings('AiScan', 'additional_prompt', ''));
        if (!empty($custom)) {
            $prompt .= "\n\n## Additional instructions\n\n" . $custom;
        }
        return $prompt;
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
        $prompt = str_replace('{{TODAY}}', date('Y-m-d'), $prompt);
        $prompt = str_replace('{{FILE_NAME}}', $fileName ?: 'unknown', $prompt);
        $prompt = str_replace('{{MIME_TYPE}}', $mimeType ?: 'unknown', $prompt);
        $prompt = str_replace('{{IMPORT_MODE}}', $importMode === 'total' ? 'total' : 'lines', $prompt);
        $lang = Tools::config('lang', 'es_ES');
        $langName = str_starts_with($lang, 'es') ? 'Spanish' : 'English';
        $prompt = str_replace('{{LANG}}', $langName, $prompt);

        $langCode = Tools::settings('default', 'codpais', '');
        $langMap = [
            'ESP' => 'Spanish', 'MEX' => 'Spanish', 'ARG' => 'Spanish', 'COL' => 'Spanish',
            'CHL' => 'Spanish', 'PER' => 'Spanish', 'ECU' => 'Spanish', 'VEN' => 'Spanish',
            'DEU' => 'German', 'AUT' => 'German', 'CHE' => 'German',
            'FRA' => 'French', 'ITA' => 'Italian', 'PRT' => 'Portuguese', 'BRA' => 'Portuguese',
            'NLD' => 'Dutch', 'POL' => 'Polish', 'ROU' => 'Romanian',
        ];
        $langName = $langMap[$langCode] ?? 'English';
        $prompt .= "\n\nIMPORTANT: All text output (warnings, summary, payment_terms, descriptions)"
            . ' MUST be written in ' . $langName . '.'
            . ' Do not use English unless the application language is English.';

        // Add our company info so AI doesn't confuse us with the supplier
        $prompt .= self::buildCompanyHint();

        // Add available tax types and withholding types for matching
        $prompt .= self::buildTaxTypesHint();

        $prompt .= $importMode === 'total' ? self::MODE_TOTAL_HINT : self::MODE_LINES_HINT;

        if (!empty(trim($historicalContext))) {
            $prompt .= "\n\n" . $historicalContext;
        }

        return $prompt;
    }

    private static function buildCompanyHint(): string
    {
        try {
            $company = new \FacturaScripts\Dinamic\Model\Empresa();
            $company->loadFromCode(Tools::settings('default', 'idempresa', 1));
            $names = array_filter([
                $company->nombre ?? '',
                $company->nombrecorto ?? '',
            ]);
            $cifnif = $company->cifnif ?? '';
            if (empty($names) && empty($cifnif)) {
                return '';
            }
            $hint = "\n\nOUR COMPANY (the buyer, NOT the supplier):\n";
            if (!empty($names)) {
                $hint .= '- Name: ' . implode(' / ', array_unique($names)) . "\n";
            }
            if (!empty($cifnif)) {
                $hint .= '- Tax ID: ' . $cifnif . "\n";
            }
            $hint .= "If this name or tax ID appears in the document, it is the CUSTOMER, not the supplier.\n";
            return $hint;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function buildTaxTypesHint(): string
    {
        $hint = "\n\nAvailable tax types (use the code in lines.tax_code):\n";
        try {
            $impuesto = new \FacturaScripts\Dinamic\Model\Impuesto();
            foreach ($impuesto->all([], ['iva' => 'ASC'], 0, 0) as $tax) {
                $hint .= "- {$tax->codimpuesto}: {$tax->descripcion} ({$tax->iva}%)\n";
            }
        } catch (\Throwable $e) {
            $hint .= "- IVA21: IVA 21%, IVA10: IVA 10%, IVA4: IVA 4%, IVA0: IVA 0%\n";
        }

        $hint .= "\nAvailable withholding types (use the code in lines.irpf_code):\n";
        try {
            $retencion = new \FacturaScripts\Dinamic\Model\Retencion();
            foreach ($retencion->all([], ['porcentaje' => 'ASC'], 0, 0) as $ret) {
                $hint .= "- {$ret->codretencion}: {$ret->descripcion} ({$ret->porcentaje}%)\n";
            }
        } catch (\Throwable $e) {
            $hint .= "- IRPF15: IRPF 15%, IRPF7: IRPF 7%\n";
        }

        $hint .= "\nFor each line, return tax_code (matching a code above) and irpf_code if withholding applies.\n";
        $hint .= "Match the tax rate to the closest available tax type code.\n";

        return $hint;
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
            // Remove control characters (keep \n for JSON structure)
            $cleaned = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $rawJson);
            $data = json_decode($cleaned, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $rawJson = $cleaned;
            }
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Clean + repair truncated JSON by closing open brackets
            $cleaned = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $rawJson);
            $repaired = self::repairTruncatedJson($cleaned);
            $data = json_decode($repaired, true);
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            Tools::log('AiScan')->error(
                'Invalid JSON from AI provider: ' . json_last_error_msg()
                . ' | File: ' . $fileName
                . ' | Response: ' . $rawJson
            );
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

    private static function repairTruncatedJson(string $json): string
    {
        // Remove trailing incomplete string value
        $json = preg_replace('/"[^"]*$/', '""', $json);
        // Remove trailing key without value
        $json = preg_replace('/,\s*"[^"]*"\s*:\s*$/', '', $json);

        // Count open brackets and close them
        $opens = substr_count($json, '{') + substr_count($json, '[');
        $closes = substr_count($json, '}') + substr_count($json, ']');
        if ($opens <= $closes) {
            return $json;
        }

        // Walk through to find which brackets are open
        $stack = [];
        $inString = false;
        $escape = false;
        for ($i = 0, $len = strlen($json); $i < $len; $i++) {
            $ch = $json[$i];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($ch === '\\' && $inString) {
                $escape = true;
                continue;
            }
            if ($ch === '"') {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($ch === '{' || $ch === '[') {
                $stack[] = $ch === '{' ? '}' : ']';
            } elseif ($ch === '}' || $ch === ']') {
                array_pop($stack);
            }
        }

        // Remove trailing comma before closing
        $json = preg_replace('/,\s*$/', '', $json);
        return $json . implode('', array_reverse($stack));
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
