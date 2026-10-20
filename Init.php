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

namespace FacturaScripts\Plugins\AiScan;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Dinamic\Model\Settings;
use FacturaScripts\Plugins\AiScan\Lib\AiScanSettings;

class Init extends InitClass
{
    public function init(): void
    {
    }

    public function update(): void
    {
        self::removeHistoryOrphans(new DataBase());

        $settings = new Settings();
        $settings->load('AiScan');
        $settings->name = 'AiScan';

        if (false === $settings->exists()) {
            foreach (AiScanSettings::getDefaults() as $key => $value) {
                $settings->$key = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
            $settings->save();
            return;
        }

        foreach (AiScanSettings::getDefaults() as $key => $value) {
            if (null === $settings->getProperty($key)) {
                $settings->$key = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        $settings->save();
    }

    /**
     * Issue #136: las tablas del historial y de alias tienen ahora claves
     * foráneas. Las filas huérfanas que ya hubiera (por ejemplo, documentos de
     * un lote borrado desde el listado) impedirían añadirlas, así que se borran
     * antes de que FacturaScripts revise las tablas.
     */
    public static function removeHistoryOrphans(DataBase $db): void
    {
        $queries = [
            'aiscan_import_documents' => 'DELETE FROM aiscan_import_documents WHERE idbatch NOT IN'
                . ' (SELECT id FROM aiscan_import_batches);',
            'aiscan_import_lines' => 'DELETE FROM aiscan_import_lines WHERE iddocument NOT IN'
                . ' (SELECT id FROM aiscan_import_documents);',
            'aiscan_supplier_aliases' => 'DELETE FROM aiscan_supplier_aliases WHERE codproveedor NOT IN'
                . ' (SELECT codproveedor FROM proveedores);',
        ];
        foreach ($queries as $table => $sql) {
            if ($db->tableExists($table)) {
                $db->exec($sql);
            }
        }
    }

    public function uninstall(): void
    {
    }
}
