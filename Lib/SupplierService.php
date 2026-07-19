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

use FacturaScripts\Dinamic\Model\Proveedor;

class SupplierService
{
    public const PARTY_SUPPLIER = 'supplier';
    public const PARTY_CREDITOR = 'creditor';

    public function resolve(array &$supplierData): ?Proveedor
    {
        // Detectar si el tipo se indicó de forma explícita ANTES de normalizar
        // el payload; si no, no debemos tocar el flag de un proveedor existente.
        $explicitPartyType = array_key_exists('is_creditor', $supplierData)
            || array_key_exists('party_type', $supplierData);
        $isCreditor = $this->resolveIsCreditor($supplierData);

        if (!empty($supplierData['matched_supplier_id'])) {
            $supplier = new Proveedor();
            if ($supplier->loadFromCode($supplierData['matched_supplier_id'])) {
                $this->applyCreditorFlag($supplier, $isCreditor, $supplierData, $explicitPartyType);
                return $supplier;
            }
        }

        $matcher = new SupplierMatcher();
        $match = $matcher->findMatch($supplierData);
        if ($match['supplier']) {
            $supplierData['matched_supplier_id'] = $match['supplier']->codproveedor;
            $this->applyCreditorFlag($match['supplier'], $isCreditor, $supplierData, $explicitPartyType);
            return $match['supplier'];
        }

        if (empty($supplierData['create_if_missing'])) {
            return null;
        }

        $supplier = new Proveedor();
        $supplier->nombre = trim((string) ($supplierData['name'] ?? ''));
        $supplier->razonsocial = $supplier->nombre;
        $supplier->cifnif = trim((string) ($supplierData['tax_id'] ?? ''));
        $supplier->email = trim((string) ($supplierData['email'] ?? ''));
        $supplier->telefono1 = trim((string) ($supplierData['phone'] ?? ''));
        $supplier->observaciones = trim((string) ($supplierData['address'] ?? ''));
        $supplier->personafisica = false;
        $supplier->acreedor = $isCreditor;

        if (empty($supplier->nombre) || false === $supplier->save()) {
            return null;
        }

        $supplierData['matched_supplier_id'] = $supplier->codproveedor;
        $supplierData['match_status'] = 'created';
        $supplierData['matched_name'] = $supplier->nombre;
        $supplierData['party_type'] = $isCreditor ? self::PARTY_CREDITOR : self::PARTY_SUPPLIER;
        $supplierData['is_creditor'] = $isCreditor;
        return $supplier;
    }

    /**
     * Normaliza el tipo de tercero enviado por la UI o el payload de importación.
     *
     * Acepta:
     * - party_type: "supplier" | "creditor" | "proveedor" | "acreedor"
     * - is_creditor: bool|int|string
     */
    public function resolveIsCreditor(array $supplierData): bool
    {
        if (array_key_exists('is_creditor', $supplierData)) {
            return $this->toBool($supplierData['is_creditor']);
        }

        $partyType = strtolower(trim((string) ($supplierData['party_type'] ?? self::PARTY_SUPPLIER)));
        return in_array($partyType, [self::PARTY_CREDITOR, 'acreedor', 'creditor'], true);
    }

    /**
     * Actualiza el flag acreedor del proveedor si la importación lo indica
     * de forma explícita y el valor actual difiere.
     *
     * En FacturaScripts el flag `Proveedor.acreedor` decide la subcuenta
     * contable especial (PROVEE vs ACREED), por lo que debe respetarse
     * la elección del usuario al importar.
     */
    private function applyCreditorFlag(
        Proveedor $supplier,
        bool $isCreditor,
        array &$supplierData,
        bool $explicitPartyType
    ): void {
        if (!$explicitPartyType) {
            $supplierData['is_creditor'] = (bool) $supplier->acreedor;
            $supplierData['party_type'] = $supplier->acreedor
                ? self::PARTY_CREDITOR
                : self::PARTY_SUPPLIER;
            return;
        }

        $supplierData['is_creditor'] = $isCreditor;
        $supplierData['party_type'] = $isCreditor ? self::PARTY_CREDITOR : self::PARTY_SUPPLIER;

        if ((bool) $supplier->acreedor === $isCreditor) {
            return;
        }

        $supplier->acreedor = $isCreditor;
        // Best-effort: si falla el save, la factura se crea igual con el
        // sujeto resuelto; el flag se reintentará en siguientes imports.
        $supplier->save();
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
    }
}
