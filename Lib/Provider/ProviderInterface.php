<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\AiScan\Lib\Provider;

interface ProviderInterface
{
    public function getName(): string;

    public function isAvailable(): bool;

    public function analyzeDocument(string $content, string $mimeType, string $prompt): string;
}
