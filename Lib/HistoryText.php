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

use FacturaScripts\Core\Tools;

/**
 * Issue #129: limpia el texto que el historial guarda y pinta sin escapar.
 */
final class HistoryText
{
    /**
     * Escapa el HTML y recorta al tamaño de la columna, para que un texto largo
     * (ya escapado) no impida guardar el registro.
     */
    public static function clean(?string $text, int $length = 0): ?string
    {
        $clean = Tools::noHtml($text);
        if ($clean === null || $length <= 0) {
            return $clean;
        }

        return mb_substr($clean, 0, $length);
    }
}
