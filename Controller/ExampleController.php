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

namespace FacturaScripts\Plugins\PluginTemplate\Controller;

use FacturaScripts\Core\Template\Controller;

/**
 * Example controller for demonstration purposes
 *
 * This is a basic controller that shows how to create a new page
 * in FacturaScripts. You can access it by going to:
 * http://yourdomain/ExampleController
 *
 * @author Your Name <your@email.com>
 */
class ExampleController extends Controller
{
    /**
     * Returns page data configuration
     *
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'example-controller';
        $data['icon'] = 'fa-solid fa-rocket';
        $data['showonmenu'] = true;
        return $data;
    }

    /**
     * Main controller execution
     *
     * @return void
     */
    public function run(): void
    {
        parent::run();

        // Your code here
        // Example: get data from database, process forms, etc.

        // You can add messages to the user:
        // $this->toolBox()->i18nLog()->notice('Hello from ExampleController!');

        // You can redirect to another page:
        // $this->redirect('ListCliente');

        // You can get request parameters:
        // $id = $this->request->get('id');
    }
}
