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

namespace FacturaScripts\Plugins\PluginTemplate\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;

/**
 * Example model for demonstration purposes
 *
 * This model represents a table in the database with some basic fields.
 * The table structure is defined in Table/example_table.xml
 *
 * @author Your Name <your@email.com>
 */
class ExampleModel extends ModelClass
{
    use ModelTrait;

    /**
     * Primary key
     *
     * @var int
     */
    public $id;

    /**
     * Name field
     *
     * @var string
     */
    public $name;

    /**
     * Description field
     *
     * @var string
     */
    public $description;

    /**
     * Active status
     *
     * @var bool
     */
    public $active;

    /**
     * Creation date
     *
     * @var string
     */
    public $created_at;

    /**
     * Update date
     *
     * @var string
     */
    public $updated_at;

    /**
     * Reset model values
     *
     * @return void
     */
    public function clear(): void
    {
        parent::clear();
        $this->active = true;
        $this->created_at = date('Y-m-d H:i:s');
        $this->updated_at = date('Y-m-d H:i:s');
    }

    /**
     * Returns the primary key column name
     *
     * @return string
     */
    public static function primaryColumn(): string
    {
        return 'id';
    }

    /**
     * Returns the table name
     *
     * @return string
     */
    public static function tableName(): string
    {
        return 'example_table';
    }

    /**
     * Returns true if there are no errors on properties values
     *
     * @return bool
     */
    public function test(): bool
    {
        // Validate name is not empty
        $this->name = trim($this->name);
        if (empty($this->name)) {
            $this->toolBox()->i18nLog()->warning('name-cannot-be-empty');
            return false;
        }

        // Update the updated_at timestamp
        $this->updated_at = date('Y-m-d H:i:s');

        return parent::test();
    }
}
