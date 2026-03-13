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

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * List controller for ExampleModel
 *
 * This controller shows a list of ExampleModel records
 * using the XMLView system
 *
 * @author Your Name <your@email.com>
 */
class ListExampleModel extends ListController
{
    /**
     * Returns basic page attributes
     *
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'example-models';
        $data['icon'] = 'fa-solid fa-list';
        return $data;
    }

    /**
     * Load views
     */
    protected function createViews(): void
    {
        $this->createViewExampleModel();
    }

    /**
     * Create and configure the ExampleModel view
     *
     * @param string $viewName
     */
    protected function createViewExampleModel(string $viewName = 'ListExampleModel'): void
    {
        $this->addView($viewName, 'ExampleModel', 'example-models', 'fa-solid fa-list');
        $this->addOrderBy($viewName, ['id'], 'id', 2);
        $this->addOrderBy($viewName, ['name'], 'name');
        $this->addOrderBy($viewName, ['created_at'], 'created-at');

        // Add search fields
        $this->addSearchFields($viewName, ['name', 'description']);

        // Add filters
        $this->addFilterCheckbox($viewName, 'active', 'active', 'active');
    }
}
