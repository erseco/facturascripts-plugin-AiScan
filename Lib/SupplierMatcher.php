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

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Proveedor;

class SupplierMatcher
{
    // Legal form suffixes to strip for normalized name matching
    private const LEGAL_FORM_PATTERN =
        '/\s*\b(S\.?R\.?L\.?|S\.?L\.?U\.?|S\.?A\.?U\.?'
        . '|S\.?L\.?L\.?|S\.?L\.?|S\.?A\.?|S\.?C\.?)\.?\s*/i';
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
            $supplier = new Proveedor();
            $where = [new Where('cifnif', '=', $supplierData['tax_id'])];
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

        if (!empty($supplierData['name'])) {
            $supplier = new Proveedor();
            $normalizedName = $this->normalizeName($supplierData['name']);
            $where = [new Where('nombre', 'LIKE', '%' . $normalizedName . '%')];
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

    private function normalizeName(string $name): string
    {
        $name = preg_replace(self::LEGAL_FORM_PATTERN, '', $name);
        return trim($name);
    }
}
