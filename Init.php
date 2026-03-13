<?php

/**
 * This file is part of PluginTemplate plugin for FacturaScripts.
 * Copyright (C) 2025 Your Name <your@email.com>
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

namespace FacturaScripts\Plugins\PluginTemplate;

use FacturaScripts\Core\Template\InitClass;

/**
 * Plugin initialization class
 *
 * This class is called when the plugin is loaded.
 * It can be used to load extensions, modify core behavior, etc.
 *
 * @author Your Name <your@email.com>
 */
class Init extends InitClass
{
    /**
     * Called when the plugin is loaded
     *
     * @return void
     */
    public function init(): void
    {
        // Load your extensions here
        // Example:
        // $this->loadExtension(new Extension\Controller\EditCliente());
        // $this->loadExtension(new Extension\Model\Cliente());
    }

    /**
     * Called when the plugin is updated
     *
     * @return void
     */
    public function update(): void
    {
        // Add update logic here if needed
        // This is called when the plugin version changes
    }

    /**
     * Called when the plugin is uninstalled
     *
     * @return void
     */
    public function uninstall(): void
    {
        // Add cleanup logic here if needed
        // This is called when the plugin is uninstalled
    }
}
