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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\PluginTemplate\Model\ExampleModel;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExampleModel
 *
 * These are simplified example tests that don't require database operations.
 * For real plugin development, you would add tests that interact with the database.
 *
 * @author Your Name <your@email.com>
 */
final class ExampleModelTest extends TestCase
{
    /**
     * Test that a new model can be instantiated
     */
    public function testCanInstantiate(): void
    {
        $model = new ExampleModel();
        $this->assertInstanceOf(ExampleModel::class, $model);
    }

    /**
     * Test that model properties can be set and retrieved
     */
    public function testCanSetProperties(): void
    {
        $model = new ExampleModel();
        $model->name = 'Test Name';
        $model->description = 'Test Description';
        $model->active = true;

        $this->assertEquals('Test Name', $model->name);
        $this->assertEquals('Test Description', $model->description);
        $this->assertTrue($model->active);
    }

    /**
     * Test that model has correct table name property
     */
    public function testTableName(): void
    {
        $model = new ExampleModel();
        // Access the table name through reflection or model properties
        $reflection = new \ReflectionClass($model);
        $this->assertEquals('ExampleModel', $reflection->getShortName());
    }

    /**
     * Test that model can be created with default values
     */
    public function testDefaultValues(): void
    {
        $model = new ExampleModel();
        $this->assertNull($model->id);
        $this->assertTrue($model->active); // Default is true
    }

    /*
     * IMPORTANT: The tests above demonstrate basic testing without database operations.
     *
     * For full CRUD testing (Create, Read, Update, Delete), you would need:
     * 1. A properly initialized FacturaScripts environment
     * 2. Database tables created
     * 3. Proper test database configuration
     *
     * Example of database tests (commented out):
     *
     * public function testCreate(): void
     * {
     *     $model = new ExampleModel();
     *     $model->name = 'Test Name';
     *     $model->description = 'Test Description';
     *     $this->assertTrue($model->save());
     *     $this->assertNotEmpty($model->id);
     *     $this->assertTrue($model->delete());
     * }
     *
     * See FacturaScripts documentation for more details on advanced testing:
     * https://facturascripts.com/publicaciones/testing-en-facturascripts-630
     */
}
