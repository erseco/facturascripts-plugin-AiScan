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
use FacturaScripts\Dinamic\Model\LineaFacturaProveedor;
use FacturaScripts\Dinamic\Model\ProductoProveedor;
use FacturaScripts\Dinamic\Model\Variante;

class PurchaseLineInventoryUpdater
{
    /**
     * @param array<int, array<string, mixed>> $sourceLines
     *
     * @return array<string, array<int, string>|int>
     */
    public function update(FacturaProveedor $invoice, array $sourceLines = []): array
    {
        $result = [
            'purchase_updates' => 0,
            'stock_updates' => 0,
            'warnings' => [],
        ];

        foreach ($invoice->getLines() as $index => $line) {
            $sourceLine = $sourceLines[$index] ?? [];
            $rawQuantity = $this->getNumber($sourceLine, ['quantity', 'cantidad'], $line->cantidad);
            $rawUnitPrice = $this->getNumber($sourceLine, ['unit_price', 'pvpunitario'], $line->pvpunitario);
            $lineNumber = (string) ($index + 1);

            $variant = new Variante();
            $hasProduct = !empty($line->referencia)
                && $variant->loadWhere([Where::eq('referencia', $line->referencia)]);
            if (false === $hasProduct) {
                $result['warnings'][] = Tools::lang()->trans(
                    'aiscan-stock-line-skipped-no-product',
                    ['%line%' => $lineNumber]
                );
                continue;
            }

            $product = $variant->getProducto();

            if ($rawQuantity <= 0) {
                $this->disableStockUpdate($line);
                $result['warnings'][] = Tools::lang()->trans(
                    'aiscan-stock-line-skipped-invalid-quantity',
                    ['%line%' => $lineNumber]
                );
            } elseif ($product->nostock) {
                $result['warnings'][] = Tools::lang()->trans(
                    'aiscan-stock-line-skipped-not-controlled',
                    ['%line%' => $lineNumber]
                );
            } elseif ($this->enableStockUpdate($line)) {
                $result['stock_updates']++;
            } else {
                $result['warnings'][] = Tools::lang()->trans(
                    'aiscan-stock-line-update-failed',
                    ['%line%' => $lineNumber]
                );
            }

            if (empty($invoice->codproveedor)) {
                continue;
            }

            if ($rawUnitPrice <= 0) {
                $result['warnings'][] = Tools::lang()->trans(
                    'aiscan-purchase-data-line-skipped-invalid-price',
                    ['%line%' => $lineNumber]
                );
                continue;
            }

            if ($this->updateSupplierProduct($invoice, $line, $rawUnitPrice, $sourceLine)) {
                $result['purchase_updates']++;
                continue;
            }

            $result['warnings'][] = Tools::lang()->trans(
                'aiscan-purchase-data-line-update-failed',
                ['%line%' => $lineNumber]
            );
        }

        return $result;
    }

    public function revertAll(FacturaProveedor $invoice): void
    {
        foreach ($invoice->getLines() as $line) {
            $this->disableStockUpdate($line);
        }
    }

    private function enableStockUpdate(LineaFacturaProveedor $line): bool
    {
        if ((int) $line->actualizastock === 1) {
            return true;
        }

        $line->actualizastock = 1;
        return $line->save();
    }

    private function disableStockUpdate(LineaFacturaProveedor $line): bool
    {
        if ((int) $line->actualizastock === 0) {
            return true;
        }

        $line->actualizastock = 0;
        return $line->save();
    }

    /**
     * @param array<string, mixed> $sourceLine
     */
    private function updateSupplierProduct(
        FacturaProveedor $invoice,
        LineaFacturaProveedor $line,
        float $unitPrice,
        array $sourceLine
    ): bool {
        $product = new ProductoProveedor();
        $where = [
            Where::eq('codproveedor', $invoice->codproveedor),
            Where::eq('referencia', $line->referencia),
            Where::eq('coddivisa', $invoice->coddivisa),
        ];

        $product->loadWhere($where);
        $product->actualizado = Tools::dateTime($invoice->fecha . ' ' . $invoice->hora);
        $product->coddivisa = $invoice->coddivisa;
        $product->codproveedor = $invoice->codproveedor;
        $product->dtopor = (float) $line->dtopor;
        $product->dtopor2 = (float) $line->dtopor2;
        $product->idproducto = $line->idproducto;
        $product->precio = $unitPrice;
        $product->referencia = $line->referencia;

        $supplierReference = trim((string) ($sourceLine['refproveedor'] ?? $sourceLine['supplier_reference'] ?? ''));
        if (!empty($supplierReference)) {
            $product->refproveedor = $supplierReference;
        }

        return $product->save();
    }

    /**
     * @param array<string, mixed> $sourceLine
     * @param array<int, string>   $keys
     */
    private function getNumber(array $sourceLine, array $keys, float $fallback): float
    {
        foreach ($keys as $key) {
            if (isset($sourceLine[$key]) && $sourceLine[$key] !== '') {
                return (float) $sourceLine[$key];
            }
        }

        return $fallback;
    }
}
