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

class SchemaValidator
{
    private const REQUIRED_TOP_LEVEL_FIELDS = ['supplier', 'invoice', 'taxes', 'lines', 'meta'];
    private const REQUIRED_INVOICE_FIELDS = ['number', 'issue_date', 'total'];

    // Regex to detect European number format (e.g. 1.234,56)
    private const EUROPEAN_NUMBER_PATTERN = '/^\d{1,3}(\.\d{3})+(,\d+)?$/';

    public function validate(array $data): array
    {
        $errors = [];

        foreach (self::REQUIRED_TOP_LEVEL_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                $errors[] = 'Missing top-level field: ' . $field;
            }
        }

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

        foreach ($data['lines'] ?? [] as $index => $line) {
            if (empty($line['description'])) {
                $errors[] = 'Missing line description at index ' . $index;
            }
        }

        if (!empty($data['invoice']['issue_date']) && 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['invoice']['issue_date'])) {
            $errors[] = 'Issue date must use YYYY-MM-DD format';
        }

        if (!empty($data['invoice']['due_date']) && 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['invoice']['due_date'])) {
            $errors[] = 'Due date must use YYYY-MM-DD format';
        }

        if (!empty($data['invoice']['currency']) && false === $this->isValidCurrencyCode($data['invoice']['currency'])) {
            $errors[] = 'Currency must be a 3-letter ISO code';
        }

        if (
            isset($data['invoice']['subtotal'])
            && isset($data['invoice']['tax_amount'])
            && isset($data['invoice']['total'])
        ) {
            $computed = (float) $data['invoice']['subtotal'] + (float) $data['invoice']['tax_amount'];
            $declared = (float) $data['invoice']['total'];
            if (abs($computed - $declared) > 0.02) {
                $errors[] = sprintf(
                    'Tax mismatch: subtotal(%.2f) + tax(%.2f) = %.2f but total is %.2f',
                    $data['invoice']['subtotal'],
                    $data['invoice']['tax_amount'],
                    $computed,
                    $declared
                );
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
        $data['meta'] = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        if (!empty($data['invoice']['issue_date'])) {
            $data['invoice']['issue_date'] = $this->normalizeDate($data['invoice']['issue_date']);
        }
        if (!empty($data['invoice']['due_date'])) {
            $data['invoice']['due_date'] = $this->normalizeDate($data['invoice']['due_date']);
        }

        foreach (['subtotal', 'tax_amount', 'total'] as $field) {
            if (isset($data['invoice'][$field])) {
                $data['invoice'][$field] = $this->normalizeDecimal($data['invoice'][$field]);
            }
        }

        if (!empty($data['invoice']['currency'])) {
            $data['invoice']['currency'] = strtoupper(trim((string) $data['invoice']['currency']));
        }

        if (isset($data['lines']) && is_array($data['lines'])) {
            foreach ($data['lines'] as &$line) {
                foreach (['quantity', 'unit_price', 'discount', 'tax_rate', 'line_total'] as $field) {
                    if (isset($line[$field])) {
                        $line[$field] = $this->normalizeDecimal($line[$field]);
                    }
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
        if (is_float($value)) {
            return $value;
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

    private function isValidCurrencyCode(string $currency): bool
    {
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            return false;
        }

        if (false === class_exists(\ResourceBundle::class)) {
            return true;
        }

        $bundle = \ResourceBundle::create('en', 'ICUDATA-curr');
        return $bundle instanceof \ResourceBundle && false !== $bundle->get($currency) && null !== $bundle->get($currency);
    }
}
