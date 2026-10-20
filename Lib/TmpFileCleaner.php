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

/**
 * Issue #130: borra los temporales de MyFiles/aiscan_tmp que nadie llegó a importar.
 */
final class TmpFileCleaner
{
    public const MAX_AGE_SECONDS = 86400;

    public static function tmpDir(): string
    {
        return FS_FOLDER . '/MyFiles/aiscan_tmp';
    }

    /**
     * Borra los archivos más antiguos que $maxAge segundos y devuelve cuántos ha borrado.
     */
    public static function clean(int $maxAge = self::MAX_AGE_SECONDS, ?string $dir = null, ?int $now = null): int
    {
        $dir = $dir ?? self::tmpDir();
        if (!is_dir($dir)) {
            return 0;
        }

        $limit = ($now ?? time()) - $maxAge;
        $deleted = 0;
        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir . '/' . $name;
            if (!is_file($path) || str_starts_with($name, '.')) {
                continue;
            }
            $mtime = filemtime($path);
            if ($mtime !== false && $mtime < $limit && unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
