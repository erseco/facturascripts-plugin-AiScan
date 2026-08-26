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

class AiScanSettings
{
    private const DEFAULTS = [
        'enabled' => true,
        'default_provider' => 'openai',
        'max_upload_size_mb' => 10,
        'allowed_extensions' => 'pdf,jpg,jpeg,png,webp',
        'auto_scan' => false,
        'debug_mode' => false,
        'max_parallel_requests' => 5,
        'request_timeout' => 120,
        'openai_model' => 'gpt-5-nano',
        'openai_base_url' => 'https://api.openai.com/v1',
        'gemini_model' => 'gemini-2.5-flash-lite',
        'mistral_model' => 'mistral-small-latest',
        'grok_model' => 'grok-4.5',
    ];

    /**
     * Setting that holds the model list of each provider. Values are stored as a
     * comma-separated list so single-model installations keep working untouched.
     */
    private const MODEL_SETTINGS = [
        'openai' => 'openai_model',
        'gemini' => 'gemini_model',
        'mistral' => 'mistral_model',
        'grok' => 'grok_model',
        'openai-compatible' => 'custom_model',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $fallback = $default ?? self::DEFAULTS[$key] ?? null;
        return Tools::settings('AiScan', $key, $fallback);
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

    public static function getRequestTimeout(): int
    {
        return max(10, (int) self::get('request_timeout', 120));
    }

    public static function getDefaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Split a stored model setting into an ordered list of model ids.
     * Blanks and duplicates are dropped; the first entry is the default one.
     *
     * @return array<int, string>
     */
    public static function parseModelList(string $raw): array
    {
        $models = [];
        foreach (explode(',', $raw) as $model) {
            $model = trim($model);
            if ($model !== '' && !in_array($model, $models, true)) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /**
     * Models configured for a provider, in preference order.
     *
     * @return array<int, string>
     */
    public static function getProviderModels(string $provider): array
    {
        $key = self::MODEL_SETTINGS[$provider] ?? null;
        if ($key === null) {
            return [];
        }

        // No explicit default: get() already falls back to DEFAULTS when unset.
        return self::parseModelList((string) self::get($key));
    }

    /**
     * Default model of a provider: the first one of its list.
     */
    public static function getDefaultModel(string $provider): string
    {
        return self::getProviderModels($provider)[0] ?? '';
    }

    /**
     * Resolve the model to use for a provider. Returns null when the requested
     * model is not one of the configured ones, so callers can reject it.
     */
    public static function resolveModel(string $provider, ?string $requested): ?string
    {
        $models = self::getProviderModels($provider);
        $requested = trim((string) $requested);

        if ($requested === '') {
            return $models[0] ?? '';
        }

        return in_array($requested, $models, true) ? $requested : null;
    }
}
