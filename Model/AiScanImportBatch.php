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
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Where;

class AiScanImportBatch extends ModelClass
{
    use ModelTrait;

    public ?int $id;
    public ?string $nick;
    public ?string $importmode;
    public ?string $provider;
    public ?int $totaldocuments;
    public ?int $importedcount;
    public ?int $discardedcount;
    public ?int $failedcount;
    public ?string $created_at;

    public function clear(): void
    {
        parent::clear();
        $this->id = 0;
        $this->nick = Session::get('user')?->nick ?? null;
        $this->importmode = 'lines';
        $this->totaldocuments = 0;
        $this->importedcount = 0;
        $this->discardedcount = 0;
        $this->failedcount = 0;
        $this->created_at = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'aiscan_import_batches';
    }

    public function install(): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . static::tableName() . ' ('
            . ' id int(11) NOT NULL AUTO_INCREMENT,'
            . ' nick varchar(50) DEFAULT NULL,'
            . ' importmode varchar(20) NOT NULL DEFAULT "lines",'
            . ' provider varchar(50) DEFAULT NULL,'
            . ' totaldocuments int(11) NOT NULL DEFAULT 0,'
            . ' importedcount int(11) NOT NULL DEFAULT 0,'
            . ' discardedcount int(11) NOT NULL DEFAULT 0,'
            . ' failedcount int(11) NOT NULL DEFAULT 0,'
            . ' created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . ' PRIMARY KEY (id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';
    }

    public function getDocuments(): array
    {
        $doc = new AiScanImportDocument();
        $where = [Where::eq('idbatch', $this->id)];
        return $doc->all($where, ['id' => 'ASC'], 0, 0);
    }

    public function url(string $type = 'auto', string $list = 'ListAiScanHistory'): string
    {
        return parent::url($type, $list);
    }
}
