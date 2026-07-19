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

namespace FacturaScripts\Plugins\AiScan\Lib\Provider;

use FacturaScripts\Plugins\AiScan\Lib\AiScanSettings;
use FacturaScripts\Plugins\AiScan\Lib\MockFixtureResolver;

/**
 * Proveedor de desarrollo: devuelve JSON de fixtures sin llamar a ninguna IA.
 * Solo está disponible cuando AiScanSettings::isDebugMode() es true.
 */
class MockProvider implements ProviderInterface
{
    private ?string $forcedFixture = null;

    public function getName(): string
    {
        return 'mock';
    }

    public function isAvailable(): bool
    {
        return AiScanSettings::isDebugMode();
    }

    /**
     * Fuerza un fixture concreto (p. ej. desde el botón "Siguiente fixture").
     */
    public function setForcedFixture(?string $name): void
    {
        $this->forcedFixture = $name;
    }

    public function analyzeDocument(
        string $content,
        string $mimeType,
        string $prompt,
        string $systemPrompt = ''
    ): string {
        $resolver = new MockFixtureResolver();

        if ($this->forcedFixture !== null && $this->forcedFixture !== '') {
            $json = $resolver->loadByName($this->forcedFixture);
            if ($json !== null) {
                return $json;
            }
        }

        // El execution prompt suele incluir el nombre del archivo subido.
        $fileName = $this->extractFileNameFromPrompt($prompt);
        $json = $resolver->loadForFileName($fileName);
        if ($json !== null) {
            return $json;
        }

        $json = $resolver->loadDefault();
        if ($json !== null) {
            return $json;
        }

        throw new \RuntimeException(
            'Mock provider: no hay fixtures en Test/fixtures/responses/. '
            . 'Añade JSON de prueba o desactiva el modo depuración.'
        );
    }

    private function extractFileNameFromPrompt(string $prompt): string
    {
        // getExecutionPrompt incluye algo como "File: xxx.pdf" o el nombre en el texto
        if (preg_match('/\bFile:\s*([^\s\n]+)/i', $prompt, $m)) {
            return basename(trim($m[1]));
        }
        if (preg_match('/\bfilename["\']?\s*[:=]\s*["\']?([^\s"\']+)/i', $prompt, $m)) {
            return basename(trim($m[1]));
        }
        if (preg_match('/\b([A-Za-z0-9._-]+\.(?:pdf|jpe?g|png|webp))\b/i', $prompt, $m)) {
            return $m[1];
        }

        return '';
    }
}
