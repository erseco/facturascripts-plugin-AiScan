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

namespace FacturaScripts\Plugins\AiScan;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Dinamic\Model\Settings;
use FacturaScripts\Plugins\AiScan\Lib\AiScanSettings;
use FacturaScripts\Plugins\AiScan\Lib\ExtractionService;

class Init extends InitClass
{
    public function init(): void
    {
        $this->loadExtension(new Extension\Controller\EditFacturaProveedor());
        $this->loadExtension(new Extension\Controller\ListFacturaProveedor());
    }

    public function update(): void
    {
        $settings = new Settings();
        $settings->loadFromCode('AiScan');
        $settings->name = 'AiScan';

        if (false === $settings->exists()) {
            foreach (AiScanSettings::getDefaults() as $key => $value) {
                $settings->$key = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
            $settings->extraction_prompt = ExtractionService::getDefaultSystemPrompt();
            $settings->save();
            return;
        }

        foreach (AiScanSettings::getDefaults() as $key => $value) {
            if (null === $settings->getProperty($key)) {
                $settings->$key = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        if (empty($settings->getProperty('extraction_prompt'))) {
            $settings->extraction_prompt = ExtractionService::getDefaultSystemPrompt();
        }

        $settings->save();
    }

    public function uninstall(): void
    {
    }
}
