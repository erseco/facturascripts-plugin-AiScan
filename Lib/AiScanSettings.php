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

namespace FacturaScripts\Plugins\AiScan\Lib;

use FacturaScripts\Core\Tools;

class AiScanSettings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return Tools::settings('AiScan', $key, $default);
    }

    public static function isEnabled(): bool
    {
        return (bool) self::get('enabled', true);
    }

    public static function getDefaultProvider(): string
    {
        return self::get('default_provider', 'openai');
    }

    public static function getMaxUploadSizeMb(): int
    {
        return (int) self::get('max_upload_size_mb', 10);
    }

    public static function getAllowedExtensions(): array
    {
        $extensions = self::get('allowed_extensions', 'pdf,jpg,jpeg,png,webp');
        return array_map('trim', explode(',', $extensions));
    }

    public static function isAutoScanEnabled(): bool
    {
        return (bool) self::get('auto_scan', false);
    }

    public static function isDebugMode(): bool
    {
        return (bool) self::get('debug_mode', false);
    }
}
