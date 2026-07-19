<?php

/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\AiScan\Lib\MockFixtureResolver;
use PHPUnit\Framework\TestCase;

/**
 * Modo mock / depuración: resolución de fixtures sin IA.
 */
final class MockFixtureResolverTest extends TestCase
{
    private MockFixtureResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new MockFixtureResolver();
    }

    /**
     * @testdox Lista al menos un fixture conocido de Test/fixtures/responses
     */
    public function testListaFixturesConocidos(): void
    {
        $names = $this->resolver->listFixtureNames();
        $this->assertNotEmpty($names);
        $this->assertContains('F-2024-004', $names);
    }

    /**
     * @testdox loadByName devuelve JSON válido
     */
    public function testLoadByNameDevuelveJsonValido(): void
    {
        $json = $this->resolver->loadByName('F-2024-004');
        $this->assertNotNull($json);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('supplier', $data);
        $this->assertArrayHasKey('invoice', $data);
    }

    /**
     * @testdox loadForFileName empareja basename y fragmentos del tmp name
     */
    public function testLoadForFileNameEmparejaNombre(): void
    {
        $this->assertNotNull($this->resolver->loadForFileName('F-2024-004.pdf'));
        $this->assertNotNull($this->resolver->loadForFileName('aiscan_F-2024-004_abc123.pdf'));
        $this->assertNull($this->resolver->loadForFileName('no-existe-xyz.pdf'));
    }

    /**
     * @testdox nextFixtureName cicla la lista
     */
    public function testNextFixtureNameCicla(): void
    {
        $names = $this->resolver->listFixtureNames();
        $this->assertGreaterThanOrEqual(2, count($names));
        $first = $names[0];
        $second = $this->resolver->nextFixtureName($first);
        $this->assertSame($names[1], $second);
        $afterLast = $this->resolver->nextFixtureName($names[count($names) - 1]);
        $this->assertSame($names[0], $afterLast);
    }
}
