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

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Plugins\AiScan\Model\AiScanSupplierAlias;

class SupplierMatcher
{
    // Legal form suffixes to strip for normalized name matching
    private const LEGAL_FORM_PATTERN =
        '/\s*\b(S\.?R\.?L\.?|S\.?L\.?U\.?|S\.?A\.?U\.?'
        . '|S\.?L\.?L\.?|S\.?L\.?|S\.?A\.?|S\.?C\.?)\.?\s*/i';

    private const TAX_ID_SCAN_LIMIT = 500;

    public function findMatch(array $supplierData): array
    {
        $result = [
            'match_status' => 'not_found',
            'supplier' => null,
            'candidates' => [],
            'match_source' => null,
        ];

        if (
            empty($supplierData['name'])
            && empty($supplierData['tax_id'])
            && empty($supplierData['iban'])
            && empty($supplierData['email'])
        ) {
            return $result;
        }

        // Issue #71: memoria de alias aprendida por corrección del usuario.
        // Solo lectura; nunca se escribe aquí (los auto-match no envenenan la tabla).
        $aliasSupplier = AiScanSupplierAlias::resolveSupplier($supplierData);
        if ($aliasSupplier instanceof Proveedor) {
            $result['match_status'] = 'matched';
            $result['supplier'] = $aliasSupplier;
            $result['match_source'] = 'alias';
            return $result;
        }

        if (!empty($supplierData['tax_id'])) {
            $taxMatches = $this->findByTaxId((string) $supplierData['tax_id']);
            if (count($taxMatches) === 1) {
                $result['match_status'] = 'matched';
                $result['supplier'] = $taxMatches[0];
                $result['match_source'] = 'tax_id';
                return $result;
            }
            if (count($taxMatches) > 1) {
                $result['match_status'] = 'ambiguous';
                $result['candidates'] = $taxMatches;
                $result['match_source'] = 'tax_id';
                return $result;
            }
        }

        if (!empty($supplierData['name'])) {
            $supplier = new Proveedor();
            $normalizedName = $this->normalizeName($supplierData['name']);
            $where = [Where::like('nombre', '%' . $normalizedName . '%')];
            $candidates = $supplier->all($where, [], 0, 5);
            if (count($candidates) === 1) {
                $result['match_status'] = 'matched';
                $result['supplier'] = $candidates[0];
                $result['match_source'] = 'name';
                return $result;
            } elseif (count($candidates) > 1) {
                $result['match_status'] = 'ambiguous';
                $result['candidates'] = $candidates;
                $result['match_source'] = 'name';
                return $result;
            }
        }

        return $result;
    }

    /**
     * Normaliza un CIF/NIF/VAT para comparación y huellas de alias.
     * Compartido con #70 y #71.
     */
    public static function normalizeTaxId(?string $taxId): string
    {
        if ($taxId === null) {
            return '';
        }

        $value = strtoupper(trim($taxId));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[\s.\-\/_]/', '', $value) ?? '';
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, 'ES') && strlen($value) > 2) {
            $value = substr($value, 2);
        }

        return $value;
    }

    /**
     * @return Proveedor[]
     */
    private function findByTaxId(string $taxId): array
    {
        $taxId = trim($taxId);
        if ($taxId === '') {
            return [];
        }

        $normalized = self::normalizeTaxId($taxId);
        $byCode = [];
        $variants = array_unique(array_filter([
            $taxId,
            $normalized,
            $normalized !== '' ? 'ES' . $normalized : '',
        ], static fn (string $v): bool => $v !== ''));

        $supplier = new Proveedor();
        foreach ($variants as $variant) {
            foreach ($supplier->all([Where::eq('cifnif', $variant)], [], 0, 10) as $row) {
                $byCode[$row->codproveedor] = $row;
            }
        }

        if ($normalized !== '') {
            $first = substr($normalized, 0, 1);
            $candidates = $supplier->all(
                [Where::like('cifnif', $first . '%')],
                [],
                0,
                self::TAX_ID_SCAN_LIMIT
            );
            foreach ($candidates as $row) {
                if (self::normalizeTaxId((string) $row->cifnif) === $normalized) {
                    $byCode[$row->codproveedor] = $row;
                }
            }
        }

        return array_values($byCode);
    }

    private function normalizeName(string $name): string
    {
        $name = preg_replace(self::LEGAL_FORM_PATTERN, '', $name);
        return trim($name);
    }
}
