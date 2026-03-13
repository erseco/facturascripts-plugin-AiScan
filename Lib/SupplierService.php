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
    public function resolve(array &$supplierData): ?Proveedor
    {
        if (!empty($supplierData['matched_supplier_id'])) {
            $supplier = new Proveedor();
            if ($supplier->loadFromCode($supplierData['matched_supplier_id'])) {
                return $supplier;
            }
        }

        $matcher = new SupplierMatcher();
        $match = $matcher->findMatch($supplierData);
        if ($match['supplier']) {
            $supplierData['matched_supplier_id'] = $match['supplier']->codproveedor;
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

        if (empty($supplier->nombre) || false === $supplier->save()) {
            return null;
        }

        $supplierData['matched_supplier_id'] = $supplier->codproveedor;
        $supplierData['match_status'] = 'created';
        $supplierData['matched_name'] = $supplier->nombre;
        return $supplier;
    }
}
