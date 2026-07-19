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
 * Resuelve respuestas JSON grabadas en Test/fixtures/responses/ para el
 * MockProvider (modo depuración sin IA).
 */
class MockFixtureResolver
{
    public function fixturesDir(): string
    {
        $candidates = [
            // Plugin montado en desarrollo (ruta habitual en Docker)
            dirname(__DIR__) . '/Test/fixtures/responses',
            // Copia en FS_FOLDER/Plugins/AiScan
            (defined('FS_FOLDER') ? FS_FOLDER : '') . '/Plugins/AiScan/Test/fixtures/responses',
        ];

        foreach ($candidates as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                return $dir;
            }
        }

        return $candidates[0];
    }

    /**
     * @return list<string> Nombres de fixture sin extensión (.json)
     */
    public function listFixtureNames(): array
    {
        $dir = $this->fixturesDir();
        if (!is_dir($dir)) {
            return [];
        }

        $names = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $names[] = pathinfo($path, PATHINFO_FILENAME);
        }
        sort($names);

        return $names;
    }

    public function loadByName(string $name): ?string
    {
        $safe = basename(preg_replace('/\.json$/i', '', $name));
        $path = $this->fixturesDir() . '/' . $safe . '.json';
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        return $json === false ? null : $json;
    }

    /**
     * Empareja el nombre del archivo subido con un fixture.
     * Ej.: "aiscan_F-2024-004_abc.pdf" o "F-2024-004.pdf" → F-2024-004.json
     */
    public function loadForFileName(string $fileName): ?string
    {
        $base = pathinfo($fileName, PATHINFO_FILENAME);
        if ($base === '') {
            return null;
        }

        // 1) Coincidencia exacta del basename
        $json = $this->loadByName($base);
        if ($json !== null) {
            return $json;
        }

        // 2) Buscar un nombre de fixture contenido en el basename (tmp de upload)
        foreach ($this->listFixtureNames() as $name) {
            if (stripos($base, $name) !== false) {
                return $this->loadByName($name);
            }
        }

        return null;
    }

    public function loadDefault(): ?string
    {
        $names = $this->listFixtureNames();
        if (empty($names)) {
            return null;
        }

        return $this->loadByName($names[0]);
    }

    /**
     * Devuelve el siguiente nombre de fixture en la lista cíclica.
     */
    public function nextFixtureName(?string $current): ?string
    {
        $names = $this->listFixtureNames();
        if (empty($names)) {
            return null;
        }

        if ($current === null || $current === '') {
            return $names[0];
        }

        $idx = array_search($current, $names, true);
        if ($idx === false) {
            return $names[0];
        }

        return $names[($idx + 1) % count($names)];
    }
}
