<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\AiScan\Lib;

use FacturaScripts\Core\Where;
use FacturaScripts\Core\Model\Proveedor;

class SupplierMatcher
{
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
        $name = preg_replace('/\b(S\.?R\.?L\.?|S\.?L\.?U\.?|S\.?A\.?U\.?|S\.?L\.?L\.?|S\.?L\.?|S\.?A\.?|S\.?C\.?)\b/i', '', $name);
        return trim($name);
    }
}
