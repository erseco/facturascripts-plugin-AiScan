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
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * Completes one extracted invoice with what FacturaScripts already knows:
 * the matching supplier, the catalogue products of its lines and any invoice
 * already booked with the same supplier number.
 *
 * Public API for other plugins (see README, "Integración con otros plugins").
 */
class InvoiceEnricher
{
    public function __construct(
        private readonly SupplierMatcher $supplierMatcher = new SupplierMatcher(),
        private readonly ProductMatcher $productMatcher = new ProductMatcher(),
        private readonly HistoricalContextService $historicalContext = new HistoricalContextService()
    ) {
    }

    /**
     * Enrich a single extracted invoice with supplier matching, product
     * suggestions and duplicate checks.
     */
    public function enrich(array $extracted): array
    {
        if (!empty($extracted['supplier'])) {
            $matchResult = $this->supplierMatcher->findMatch($extracted['supplier']);
            $extracted['supplier']['match_status'] = $matchResult['match_status'];
            if ($matchResult['supplier']) {
                $extracted['supplier']['matched_supplier_id'] = $matchResult['supplier']->codproveedor;
                $extracted['supplier']['matched_name'] = $matchResult['supplier']->nombre;
                if (!empty($matchResult['match_source'])) {
                    $extracted['supplier']['match_source'] = $matchResult['match_source'];
                }
                $extracted = (new SchemaValidator())->completeMatchedSupplierTaxId(
                    $extracted,
                    (string) $matchResult['supplier']->cifnif
                );
            }
            if (!empty($matchResult['candidates'])) {
                $extracted['supplier']['candidates'] = array_map(
                    [self::class, 'supplierToArray'],
                    $matchResult['candidates']
                );
            }

            // issue #53: pre-fill lines without a product using the supplier's
            // usual product (most repeated, ties broken by most recent).
            if (!empty($extracted['supplier']['matched_supplier_id'])) {
                $this->suggestSupplierProducts($extracted);
            }
        }

        // Check for duplicate invoice already in FacturaScripts
        $duplicateWarning = $this->findDuplicateInvoice($extracted);
        if ($duplicateWarning) {
            $extracted['_duplicate'] = $duplicateWarning;
            $extracted['_validation_errors'][] = $duplicateWarning['message'];
        }

        return $extracted;
    }

    /**
     * Returns the supplier invoice already booked with the same supplier and
     * number (and date, when known), or null when there is none.
     */
    public function findDuplicateInvoice(array $extracted): ?array
    {
        $invoice = $extracted['invoice'] ?? [];
        $supplier = $extracted['supplier'] ?? [];

        $numproveedor = trim((string) ($invoice['number'] ?? ''));
        $fecha = trim((string) ($invoice['issue_date'] ?? ''));
        $codproveedor = $supplier['matched_supplier_id'] ?? '';
        $total = (float) ($invoice['total'] ?? 0);

        if (empty($numproveedor) || empty($codproveedor)) {
            return null;
        }

        $where = [
            new Where('numproveedor', $numproveedor),
            new Where('codproveedor', $codproveedor),
        ];

        if (!empty($fecha)) {
            $where[] = new Where('fecha', $fecha);
        }

        $matches = (new FacturaProveedor())->all($where, [], 0, 1);
        if (empty($matches)) {
            return null;
        }

        $existing = $matches[0];
        // Issue #115: identical total means a true duplicate that must not be imported again.
        $sameTotal = abs((float) $existing->total - $total) < 0.01;
        return [
            'type' => 'existing_invoice',
            'invoice_id' => $existing->idfactura,
            'invoice_code' => $existing->codigo,
            'same_total' => $sameTotal,
            'message' => Tools::lang()->trans(
                $sameTotal ? 'aiscan-duplicate-invoice-blocked' : 'aiscan-duplicate-invoice-exists',
                [
                    '%code%' => $existing->codigo,
                    '%date%' => $existing->fecha,
                    '%total%' => Tools::money((float) $existing->total, $existing->coddivisa),
                ]
            ),
        ];
    }

    /**
     * Pre-fills extracted lines that have no *valid* product reference with the
     * supplier's usual product, flagging them as a history suggestion so the
     * review screen shows a distinct (editable) badge. Issue #53.
     *
     * - Clears references invented by the AI that are not in the catalogue.
     * - Links a line whose description matches exactly one product first.
     * - Accepts the suggestion from a pinned product or from the supplier's
     *   history (by reference or by description).
     */
    private function suggestSupplierProducts(array &$extracted): void
    {
        $codproveedor = (string) ($extracted['supplier']['matched_supplier_id'] ?? '');
        if ($codproveedor === '') {
            return;
        }

        $suggestion = $this->historicalContext->getSuggestedProduct($codproveedor);
        if (empty($suggestion['referencia'])) {
            $extracted['_product_suggestion'] = null;
            return;
        }

        // Issue #69: expose the suggestion even without lines (total mode),
        // so the UI and buildTotalLines can apply the pinned product.
        $extracted['_product_suggestion'] = $suggestion;

        if (empty($extracted['lines']) || !is_array($extracted['lines'])) {
            return;
        }

        $variant = new Variante();
        foreach ($extracted['lines'] as &$line) {
            $currentRef = trim((string) ($line['referencia'] ?? $line['sku'] ?? ''));
            if ($currentRef !== '') {
                // Keep it only when the product exists; otherwise it is AI noise.
                if ($variant->loadWhere([Where::eq('referencia', $currentRef)])) {
                    continue;
                }
                // Also accept an exact barcode.
                if ($variant->loadWhere([Where::eq('codbarras', $currentRef)])) {
                    $line['referencia'] = $variant->referencia;
                    continue;
                }
                // Invalid reference: the suggestion may replace it.
                $line['referencia'] = '';
                unset($line['sku']);
            }

            // A unique description match beats the supplier's usual product,
            // as ProductMatcher::findReference() documents.
            $matched = $this->productMatcher->findReference($line);
            if ($matched !== null) {
                $line['referencia'] = $matched;
                continue;
            }

            $line['referencia'] = $suggestion['referencia'];
            $line['referencia_source'] = 'history';
        }
        unset($line);
    }

    private static function supplierToArray(object $s): array
    {
        return [
            'id' => $s->codproveedor,
            'name' => $s->nombre,
            'tax_id' => $s->cifnif,
        ];
    }
}
