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
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Plugins\AiScan\Model\AiScanSupplierProduct;

class HistoricalContextService
{
    private const MAX_INVOICES = 5;
    private const MAX_LINES_PER_INVOICE = 10;

    public function buildContext(string $codproveedor): array
    {
        if (empty($codproveedor)) {
            return [];
        }

        $invoice = new FacturaProveedor();
        $where = [Where::eq('codproveedor', $codproveedor)];
        $orderBy = ['fecha' => 'DESC'];
        $invoices = $invoice->all($where, $orderBy, 0, self::MAX_INVOICES);

        if (empty($invoices)) {
            return [];
        }

        $context = [];
        foreach ($invoices as $inv) {
            $lines = array_slice($inv->getLines(), 0, self::MAX_LINES_PER_INVOICE);
            $linesSummary = [];
            foreach ($lines as $line) {
                $linesSummary[] = [
                    'description' => mb_substr(trim($line->descripcion), 0, 100),
                    'quantity' => $line->cantidad,
                    'unit_price' => $line->pvpunitario,
                    'tax_rate' => $line->iva,
                ];
            }

            $context[] = [
                'number' => $inv->numproveedor ?: $inv->codigo,
                'date' => $inv->fecha,
                'total' => $inv->total,
                'currency' => $inv->coddivisa,
                'lines' => $linesSummary,
            ];
        }

        return $context;
    }

    public function formatForPrompt(array $context): string
    {
        if (empty($context)) {
            return '';
        }

        $parts = ['Previous invoices from this supplier (secondary reference):'];
        foreach ($context as $index => $inv) {
            $num = $index + 1;
            $number = $inv['number'];
            $date = $inv['date'];
            $total = $inv['total'];
            $currency = $inv['currency'];
            $parts[] = "  Invoice {$num}: #{$number} ({$date})"
                . " - Total: {$total} {$currency}";
            foreach ($inv['lines'] as $line) {
                $desc = $line['description'];
                $qty = $line['quantity'];
                $price = $line['unit_price'];
                $tax = $line['tax_rate'];
                $parts[] = "    - {$desc} (qty: {$qty},"
                    . " price: {$price}, tax: {$tax}%)";
            }
        }
        $parts[] = 'Use this context to improve field matching '
            . 'but always prioritize evidence from the current document.';

        return implode("\n", $parts);
    }

    /**
     * Suggests the product this supplier most likely bills, to pre-fill lines
     * that could not be matched otherwise (issue #53).
     *
     * Priority:
     *   1. The product manually pinned for the supplier (AiScanSupplierProduct).
     *   2. The most frequent product across previous invoices
     *      (ties broken by most recent), via rankProductsFromLines().
     *
     * @return array{referencia: string, description: string}|null
     */
    public function getSuggestedProduct(string $codproveedor): ?array
    {
        if (empty($codproveedor)) {
            return null;
        }

        $pinned = AiScanSupplierProduct::getForSupplier($codproveedor);
        if ($pinned && !empty($pinned->referencia) && $this->productReferenceExists($pinned->referencia)) {
            return [
                'referencia' => $pinned->referencia,
                'description' => (string) ($pinned->description ?? ''),
                'source' => 'pinned',
            ];
        }

        $invoice = new FacturaProveedor();
        $where = [Where::eq('codproveedor', $codproveedor)];
        $orderBy = ['fecha' => 'DESC'];
        $invoices = $invoice->all($where, $orderBy, 0, self::MAX_INVOICES);

        if (empty($invoices)) {
            return null;
        }

        $historyLines = [];
        foreach ($invoices as $inv) {
            foreach ($inv->getLines() as $line) {
                $historyLines[] = [
                    'referencia' => (string) ($line->referencia ?? ''),
                    'description' => mb_substr(trim((string) $line->descripcion), 0, 100),
                    'date' => (string) $inv->fecha,
                ];
            }
        }

        // 1) Ranking por referencias ya enlazadas en facturas previas
        $ranking = self::rankProductsFromLines($historyLines);
        foreach ($ranking as $item) {
            if ($this->productReferenceExists($item['referencia'])) {
                return [
                    'referencia' => $item['referencia'],
                    'description' => $item['description'],
                    'source' => 'history_reference',
                ];
            }
        }

        // 2) Fallback: descripciones históricas sin referencia → buscar producto
        //    (muy frecuente si las facturas previas se importaron sin enlazar).
        $descRanking = self::rankDescriptionsFromLines($historyLines);
        $matcher = new ProductMatcher();
        foreach ($descRanking as $item) {
            $ref = $matcher->findReference([
                'sku' => '',
                'description' => $item['description'],
            ]);
            if ($ref !== null && $ref !== '') {
                return [
                    'referencia' => $ref,
                    'description' => $item['description'],
                    'source' => 'history_description',
                ];
            }
        }

        return null;
    }

    /**
     * Ranking por descripción de línea (sin referencia): frecuencia + fecha.
     *
     * @param array<array{referencia?: string, description?: string, date?: string}> $lines
     *
     * @return array<array{description: string, count: int, last_date: string}>
     */
    public static function rankDescriptionsFromLines(array $lines): array
    {
        $stats = [];
        foreach ($lines as $line) {
            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '' || mb_strlen($description) < 4) {
                continue;
            }
            // Si ya tiene referencia, no la usamos aquí (ya se rankeó por ref).
            if (trim((string) ($line['referencia'] ?? '')) !== '') {
                continue;
            }

            $key = mb_strtolower($description);
            $date = (string) ($line['date'] ?? '');
            if (!isset($stats[$key])) {
                $stats[$key] = [
                    'description' => $description,
                    'count' => 0,
                    'last_date' => $date,
                ];
            }
            $stats[$key]['count']++;
            if ($date > $stats[$key]['last_date']) {
                $stats[$key]['last_date'] = $date;
                $stats[$key]['description'] = $description;
            }
        }

        $ranking = array_values($stats);
        usort($ranking, static function (array $a, array $b): int {
            return [$b['count'], $b['last_date']] <=> [$a['count'], $a['last_date']];
        });

        return $ranking;
    }

    private function productReferenceExists(string $referencia): bool
    {
        $referencia = trim($referencia);
        if ($referencia === '') {
            return false;
        }

        $variant = new \FacturaScripts\Dinamic\Model\Variante();
        return $variant->loadWhere([Where::eq('referencia', $referencia)]);
    }

    /**
     * Pure ranking helper (no DB): orders product references by frequency
     * (descending), breaking ties by the most recent date. Lines without a
     * referencia are ignored.
     *
     * @param array<array{referencia?: string, description?: string, date?: string}> $lines
     *
     * @return array<array{referencia: string, description: string, count: int, last_date: string}>
     */
    public static function rankProductsFromLines(array $lines): array
    {
        $stats = [];
        foreach ($lines as $line) {
            $referencia = trim((string) ($line['referencia'] ?? ''));
            if ($referencia === '') {
                continue;
            }

            $date = (string) ($line['date'] ?? '');
            if (!isset($stats[$referencia])) {
                $stats[$referencia] = [
                    'referencia' => $referencia,
                    'description' => (string) ($line['description'] ?? ''),
                    'count' => 0,
                    'last_date' => $date,
                ];
            }

            $stats[$referencia]['count']++;
            if ($date > $stats[$referencia]['last_date']) {
                $stats[$referencia]['last_date'] = $date;
                if (!empty($line['description'])) {
                    $stats[$referencia]['description'] = (string) $line['description'];
                }
            }
        }

        $ranking = array_values($stats);
        usort($ranking, static function (array $a, array $b): int {
            return [$b['count'], $b['last_date']] <=> [$a['count'], $a['last_date']];
        });

        return $ranking;
    }
}
