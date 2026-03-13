<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Ernesto Serrano <ernesto@erseco.es>
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

class AiScanLog extends ModelClass
{
    use ModelTrait;

    public int $id;
    public ?int $idfactura;
    public string $filename;
    public string $mime_type;
    public string $provider;
    public ?string $model;
    public string $status;
    public ?string $raw_payload;
    public ?string $error_message;
    public float $confidence;
    public string $created_at;

    public function clear(): void
    {
        parent::clear();
        $this->id = 0;
        $this->status = 'pending';
        $this->confidence = 0.0;
        $this->created_at = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'aiscan_logs';
    }

    public function test(): bool
    {
        if (empty($this->filename)) {
            $this->toolBox()->i18nLog()->warning('aiscan-filename-required');
            return false;
        }
        return parent::test();
    }
}
