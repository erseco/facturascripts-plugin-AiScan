<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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
