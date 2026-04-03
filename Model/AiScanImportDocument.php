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

class AiScanImportDocument extends ModelClass
{
    use ModelTrait;

    public ?int $id;
    public ?int $idbatch;
    public ?string $originalname;
    public ?string $codproveedor;
    public ?string $suppliername;
    public ?string $numproveedor;
    public ?string $fecha;
    public ?string $coddivisa;
    public ?float $neto;
    public ?float $totaliva;
    public ?float $total;
    public ?string $status;
    public ?int $idfactura;
    public ?string $codigofactura;
    public ?string $errormessage;
    public ?string $created_at;

    public function clear(): void
    {
        parent::clear();
        $this->id = 0;
        $this->idbatch = 0;
        $this->originalname = '';
        $this->coddivisa = 'EUR';
        $this->neto = 0.0;
        $this->totaliva = 0.0;
        $this->total = 0.0;
        $this->status = 'pending';
        $this->created_at = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'aiscan_import_documents';
    }

    public function getLines(): array
    {
        $line = new AiScanImportLine();
        $where = [Where::eq('iddocument', $this->id)];
        return $line->all($where, ['sortorder' => 'ASC'], 0, 0);
    }

    public function invoiceUrl(): string
    {
        if ($this->idfactura) {
            return 'EditFacturaProveedor?code=' . $this->idfactura;
        }
        return '';
    }

    public function url(string $type = 'auto', string $list = 'ListAiScanHistory'): string
    {
        return parent::url($type, $list);
    }
}
