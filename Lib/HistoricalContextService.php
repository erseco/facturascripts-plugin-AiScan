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
}
