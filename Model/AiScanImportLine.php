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

class AiScanImportLine extends ModelClass
{
    use ModelTrait;

    public ?int $id;
    public ?int $iddocument;
    public ?int $sortorder;
    public ?string $descripcion;
    public ?float $cantidad;
    public ?float $pvpunitario;
    public ?float $dtopor;
    public ?float $iva;
    public ?float $pvptotal;
    public ?string $referencia;

    public function clear(): void
    {
        parent::clear();
        $this->id = 0;
        $this->iddocument = 0;
        $this->sortorder = 0;
        $this->descripcion = '';
        $this->cantidad = 1.0;
        $this->pvpunitario = 0.0;
        $this->dtopor = 0.0;
        $this->iva = 0.0;
        $this->pvptotal = 0.0;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'aiscan_import_lines';
    }
}
