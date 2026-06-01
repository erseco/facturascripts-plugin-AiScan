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
use FacturaScripts\Dinamic\Model\Variante;

class ProductMatcher
{
    /**
     * Resolves the product reference for a line.
     *
     * Order of precedence: exact SKU/barcode, then a unique description match.
     * As a last resort (issue #53), if a supplier-history suggestion is given,
     * it is returned so the line is pre-filled with the supplier's usual
     * product instead of staying unlinked. The suggestion never overrides a
     * real match.
     */
    public function findReference(array $lineData, ?string $suggestedReference = null): ?string
    {
        $sku = trim((string) ($lineData['sku'] ?? ''));
        if (!empty($sku)) {
            $variant = new Variante();
            $whereReference = [Where::eq('referencia', $sku)];
            $whereBarcode = [Where::eq('codbarras', $sku)];
            if ($variant->loadWhere($whereReference) || $variant->loadWhere($whereBarcode)) {
                return $variant->referencia;
            }
        }

        $description = trim((string) ($lineData['description'] ?? ''));
        if (mb_strlen($description) >= 4) {
            $variant = new Variante();
            $results = $variant->codeModelSearch($description, 'referencia');
            if (count($results) === 1) {
                return $results[0]->code ?? null;
            }
        }

        $suggestion = trim((string) ($suggestedReference ?? ''));
        return $suggestion !== '' ? $suggestion : null;
    }
}
