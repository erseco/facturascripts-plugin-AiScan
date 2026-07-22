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

class SupplierMatcher
{
    // Legal form suffixes to strip for normalized name matching
    private const LEGAL_FORM_PATTERN =
        '/\s*\b(S\.?R\.?L\.?|S\.?L\.?U\.?|S\.?A\.?U\.?'
        . '|S\.?L\.?L\.?|S\.?L\.?|S\.?A\.?|S\.?C\.?)\.?\s*/i';

    /**
     * Max candidates to scan when comparing normalized tax IDs in PHP.
     * Keeps the fallback bounded for large supplier tables.
     */
    private const TAX_ID_SCAN_LIMIT = 500;

    public function findMatch(array $supplierData): array
    {
        $result = [
            'match_status' => 'not_found',
            'supplier' => null,
            'candidates' => [],
        ];

        if (empty($supplierData['name']) && empty($supplierData['tax_id'])) {
            return $result;
        }

        if (!empty($supplierData['tax_id'])) {
            $taxMatches = $this->findByTaxId((string) $supplierData['tax_id']);
            if (count($taxMatches) === 1) {
                $result['match_status'] = 'matched';
                $result['supplier'] = $taxMatches[0];
                return $result;
            }
            if (count($taxMatches) > 1) {
                $result['match_status'] = 'ambiguous';
                $result['candidates'] = $taxMatches;
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
                return $result;
            } elseif (count($candidates) > 1) {
                $result['match_status'] = 'ambiguous';
                $result['candidates'] = $candidates;
                return $result;
            }
        }

        return $result;
    }

    /**
     * Normaliza un CIF/NIF/VAT para comparación:
     * mayúsculas, sin espacios/puntos/guiones/barras, sin prefijo de país ES.
     *
     * Issue #70: la IA y FacturaScripts no siempre guardan el mismo formato.
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

        // Prefijo ISO de España solo si va seguido de un identificador fiscal.
        if (str_starts_with($value, 'ES') && strlen($value) > 2) {
            $value = substr($value, 2);
        }

        return $value;
    }

    /**
     * Busca proveedores por CIF/NIF con igualdad exacta y, si falla, por
     * valor normalizado (comparación en PHP sobre un conjunto acotado).
     *
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

        // 1) Igualdad exacta y variantes habituales (rápido, usa índice).
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

        // 2) Ampliar con un conjunto acotado y comparar normalizado en PHP.
        //    El valor en BD puede llevar puntos/guiones (B-35.123.456), así que un
        //    LIKE sobre el CIF ya normalizado (B35123456) no lo encuentra: se busca
        //    por la primera letra/dígito del identificador y se filtra en PHP.
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
