<?php

/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\AiScan\Lib\HistoricalContextService;
use PHPUnit\Framework\TestCase;

final class HistoricalContextServiceTest extends TestCase
{
    public function testRankByFrequency(): void
    {
        $lines = [
            ['referencia' => 'A', 'description' => 'Alpha', 'date' => '2026-01-01'],
            ['referencia' => 'B', 'description' => 'Beta', 'date' => '2026-01-02'],
            ['referencia' => 'A', 'description' => 'Alpha', 'date' => '2026-01-03'],
        ];

        $ranking = HistoricalContextService::rankProductsFromLines($lines);

        $this->assertSame('A', $ranking[0]['referencia']);
        $this->assertSame(2, $ranking[0]['count']);
        $this->assertSame('B', $ranking[1]['referencia']);
    }

    public function testTieBrokenByMostRecentDate(): void
    {
        $lines = [
            ['referencia' => 'OLD', 'description' => 'Old', 'date' => '2026-01-01'],
            ['referencia' => 'NEW', 'description' => 'New', 'date' => '2026-05-01'],
        ];

        $ranking = HistoricalContextService::rankProductsFromLines($lines);

        // Same count (1 each) -> the most recent wins the tie.
        $this->assertSame('NEW', $ranking[0]['referencia']);
        $this->assertSame('OLD', $ranking[1]['referencia']);
    }

    public function testIgnoresLinesWithoutReference(): void
    {
        $lines = [
            ['referencia' => '', 'description' => 'No product', 'date' => '2026-01-01'],
            ['referencia' => '  ', 'description' => 'Blank', 'date' => '2026-01-02'],
            ['referencia' => 'X', 'description' => 'Real', 'date' => '2026-01-03'],
        ];

        $ranking = HistoricalContextService::rankProductsFromLines($lines);

        $this->assertCount(1, $ranking);
        $this->assertSame('X', $ranking[0]['referencia']);
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        $this->assertSame([], HistoricalContextService::rankProductsFromLines([]));
    }

    public function testDescriptionFollowsMostRecentOccurrence(): void
    {
        $lines = [
            ['referencia' => 'A', 'description' => 'Old name', 'date' => '2026-01-01'],
            ['referencia' => 'A', 'description' => 'New name', 'date' => '2026-02-01'],
        ];

        $ranking = HistoricalContextService::rankProductsFromLines($lines);

        $this->assertSame('New name', $ranking[0]['description']);
    }

    /**
     * @testdox Ranking por descripción ignora líneas con referencia y ordena por frecuencia
     */
    public function testRankDescriptionsFromLines(): void
    {
        $lines = [
            ['referencia' => '', 'description' => 'Servicio recurrent', 'date' => '2026-01-01'],
            ['referencia' => 'SKIP', 'description' => 'Con ref', 'date' => '2026-01-02'],
            ['referencia' => '', 'description' => 'Otro', 'date' => '2026-01-03'],
            ['referencia' => '', 'description' => 'Servicio recurrent', 'date' => '2026-02-01'],
            ['referencia' => '', 'description' => 'ab', 'date' => '2026-03-01'], // too short
        ];

        $ranking = HistoricalContextService::rankDescriptionsFromLines($lines);

        $this->assertSame('Servicio recurrent', $ranking[0]['description']);
        $this->assertSame(2, $ranking[0]['count']);
        $this->assertSame('Otro', $ranking[1]['description']);
        foreach ($ranking as $item) {
            $this->assertGreaterThanOrEqual(4, mb_strlen($item['description']));
        }
    }
}
