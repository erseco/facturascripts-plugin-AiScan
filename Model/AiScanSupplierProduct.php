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

namespace FacturaScripts\Plugins\AiScan\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Where;

class AiScanSupplierProduct extends ModelClass
{
    use ModelTrait;

    public int $id;
    public string $codproveedor;
    public string $referencia;
    public ?string $description;
    public string $created_at;

    public function clear(): void
    {
        parent::clear();
        $this->id = 0;
        $this->codproveedor = '';
        $this->referencia = '';
        $this->description = null;
        $this->created_at = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'aiscan_supplier_products';
    }

    public function test(): bool
    {
        if (empty($this->codproveedor) || empty($this->referencia)) {
            return false;
        }
        return parent::test();
    }

    public static function getForSupplier(string $codproveedor): ?self
    {
        $model = new self();
        $where = [Where::eq('codproveedor', $codproveedor)];
        if ($model->loadWhere($where)) {
            return $model;
        }
        return null;
    }

    public static function setForSupplier(string $codproveedor, string $referencia, string $description = ''): bool
    {
        $existing = self::getForSupplier($codproveedor);
        if ($existing) {
            $existing->referencia = $referencia;
            $existing->description = $description ?: $existing->description;
            return $existing->save();
        }

        $model = new self();
        $model->codproveedor = $codproveedor;
        $model->referencia = $referencia;
        $model->description = $description;
        return $model->save();
    }
}
