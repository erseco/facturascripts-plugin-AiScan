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

class SchemaValidator
{
    private const REQUIRED_INVOICE_FIELDS = ['number', 'issue_date', 'total'];

    // Regex to detect European number format (e.g. 1.234,56)
    private const EUROPEAN_NUMBER_PATTERN = '/^\d{1,3}(\.\d{3})+(,\d+)?$/';

    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['invoice']) || false === is_array($data['invoice'])) {
            $errors[] = 'Missing invoice section';
            return $errors;
        }

        foreach (self::REQUIRED_INVOICE_FIELDS as $field) {
            if (empty($data['invoice'][$field])) {
                $errors[] = 'Missing required invoice field: ' . $field;
            }
        }

        if (isset($data['taxes']) && false === is_array($data['taxes'])) {
            $errors[] = 'Taxes must be an array';
        }

        if (isset($data['lines']) && false === is_array($data['lines'])) {
            $errors[] = 'Lines must be an array';
        }

        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
        foreach ($lines as $index => $line) {
            if (empty($line['description'])) {
                $errors[] = 'Missing line description at index ' . $index;
            }
        }

        if (
            !empty($data['invoice']['issue_date'])
            && 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['invoice']['issue_date'])
        ) {
            $errors[] = 'Issue date must use YYYY-MM-DD format';
        }

        if (
            !empty($data['invoice']['due_date'])
            && 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['invoice']['due_date'])
        ) {
            $errors[] = 'Due date must use YYYY-MM-DD format';
        }

        if (
            !empty($data['invoice']['currency'])
            && false === $this->isValidCurrencyCode($data['invoice']['currency'])
        ) {
            $errors[] = 'Currency must be a 3-letter ISO code';
        }

        if (
            isset($data['invoice']['subtotal'])
            && isset($data['invoice']['tax_amount'])
            && isset($data['invoice']['total'])
        ) {
            $withholding = (float) ($data['invoice']['withholding_amount'] ?? 0);
            $computed = (float) $data['invoice']['subtotal'] + (float) $data['invoice']['tax_amount'] - $withholding;
            $declared = (float) $data['invoice']['total'];
            if (abs($computed - $declared) > 0.02) {
                $errors[] = sprintf(
                    'Tax mismatch: subtotal(%.2f) + tax(%.2f) - withholding(%.2f) = %.2f but total is %.2f',
                    $data['invoice']['subtotal'],
                    $data['invoice']['tax_amount'],
                    $withholding,
                    $computed,
                    $declared
                );
            }
        }

        // Include AI-generated warnings
        if (!empty($data['warnings']) && is_array($data['warnings'])) {
            foreach ($data['warnings'] as $warning) {
                if (is_string($warning) && !empty($warning)) {
                    $errors[] = $warning;
                }
            }
        }

        return $errors;
    }

    public function normalize(array $data): array
    {
        $data['supplier'] = is_array($data['supplier'] ?? null) ? $data['supplier'] : [];
        $data['invoice'] = is_array($data['invoice'] ?? null) ? $data['invoice'] : [];
        $data['taxes'] = is_array($data['taxes'] ?? null) ? $data['taxes'] : [];
        $data['lines'] = is_array($data['lines'] ?? null) ? $data['lines'] : [];
        $data['warnings'] = is_array($data['warnings'] ?? null) ? $data['warnings'] : [];
        $data['confidence'] = is_array($data['confidence'] ?? null) ? $data['confidence'] : [];
        $data['customer'] = is_array($data['customer'] ?? null) ? $data['customer'] : [];

        if (!empty($data['invoice']['issue_date'])) {
            $data['invoice']['issue_date'] = $this->normalizeDate($data['invoice']['issue_date']);
        }
        if (!empty($data['invoice']['due_date'])) {
            $data['invoice']['due_date'] = $this->normalizeDate($data['invoice']['due_date']);
        }

        foreach (['subtotal', 'tax_amount', 'withholding_amount', 'total'] as $field) {
            if (isset($data['invoice'][$field])) {
                $data['invoice'][$field] = $this->normalizeDecimal($data['invoice'][$field]);
            }
        }

        // Ensure withholding is always positive (absolute value)
        if (isset($data['invoice']['withholding_amount']) && $data['invoice']['withholding_amount'] < 0) {
            $data['invoice']['withholding_amount'] = abs($data['invoice']['withholding_amount']);
        }

        if (!empty($data['invoice']['currency'])) {
            $raw = strtoupper(trim((string) $data['invoice']['currency']));
            $data['invoice']['currency'] = $this->normalizeCurrencySymbol($raw);
        }

        if (isset($data['lines']) && is_array($data['lines'])) {
            foreach ($data['lines'] as &$line) {
                // Map old field names to FS field names
                $aliases = [
                    'description' => 'descripcion',
                    'quantity' => 'cantidad',
                    'unit_price' => 'pvpunitario',
                    'discount' => 'dtopor',
                    'tax_rate' => 'iva',
                    'tax_code' => 'codimpuesto',
                    'irpf_code' => 'codretencion',
                    'line_total' => 'pvptotal',
                    'sku' => 'referencia',
                ];
                foreach ($aliases as $old => $new) {
                    if (isset($line[$old]) && !isset($line[$new])) {
                        $line[$new] = $line[$old];
                    }
                }

                // Normalize decimal fields
                $decimalFields = ['cantidad', 'pvpunitario', 'dtopor', 'dtopor2',
                    'iva', 'recargo', 'irpf', 'pvptotal'];
                foreach ($decimalFields as $field) {
                    if (isset($line[$field])) {
                        $line[$field] = $this->normalizeDecimal($line[$field]);
                    }
                }

                // Default cantidad to 1 if missing
                if (empty($line['cantidad'])) {
                    $line['cantidad'] = 1.0;
                }

                // Default numeric fields
                $line['dtopor'] = (float) ($line['dtopor'] ?? 0);
                $line['dtopor2'] = (float) ($line['dtopor2'] ?? 0);
                $line['iva'] = (float) ($line['iva'] ?? 0);
                $line['recargo'] = (float) ($line['recargo'] ?? 0);
                $line['irpf'] = (float) ($line['irpf'] ?? 0);
                $line['suplido'] = !empty($line['suplido']);

                // Try to compute pvpunitario from pvptotal if missing
                if (empty($line['pvpunitario']) && !empty($line['pvptotal'])) {
                    $qty = (float) ($line['cantidad'] ?: 1);
                    $line['pvpunitario'] = $qty > 0
                        ? $line['pvptotal'] / $qty
                        : $line['pvptotal'];
                }
            }
        }

        if (isset($data['taxes']) && is_array($data['taxes'])) {
            foreach ($data['taxes'] as &$tax) {
                foreach (['rate', 'base', 'amount'] as $field) {
                    if (isset($tax[$field])) {
                        $tax[$field] = $this->normalizeDecimal($tax[$field]);
                    }
                }
            }
        }

        return $data;
    }

    private function normalizeDate(string $date): string
    {
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'];
        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $date);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        $ts = strtotime($date);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        return $date;
    }

    private function normalizeDecimal(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        $str = (string) $value;
        // Handle European format: 1.234,56 → 1234.56
        if (preg_match(self::EUROPEAN_NUMBER_PATTERN, $str)) {
            $str = str_replace('.', '', $str);
            $str = str_replace(',', '.', $str);
        } else {
            $str = str_replace(',', '.', $str);
        }
        return (float) $str;
    }

    private function normalizeCurrencySymbol(string $currency): string
    {
        $symbolMap = [
            '€' => 'EUR', '$' => 'USD', '£' => 'GBP', '¥' => 'JPY',
        ];

        return $symbolMap[$currency] ?? $currency;
    }

    private function isValidCurrencyCode(string $currency): bool
    {
        return 1 === preg_match('/^[A-Z]{3}$/', $currency);
    }
}
