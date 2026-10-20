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

use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Plugins\AiScan\Lib\TmpFileCleaner;

class Cron extends CronClass
{
    public function run(): void
    {
        // Issue #130: los documentos subidos y no importados se quedaban para siempre.
        $this->job('aiscan-clean-tmp')->every('1 hour')->run(function () {
            TmpFileCleaner::clean();
        });
    }
}
