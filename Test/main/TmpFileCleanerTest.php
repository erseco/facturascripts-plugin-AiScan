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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\CronJob;
use FacturaScripts\Plugins\AiScan\Cron;
use FacturaScripts\Plugins\AiScan\Lib\TmpFileCleaner;
use PHPUnit\Framework\TestCase;

/**
 * Issue #130: los temporales abandonados en MyFiles/aiscan_tmp nunca se borraban.
 */
final class TmpFileCleanerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aiscan-tmp-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $name) {
            if (is_file($this->dir . '/' . $name)) {
                unlink($this->dir . '/' . $name);
            }
        }
        rmdir($this->dir);
    }

    public function testDeletesOnlyFilesOlderThanMaxAge(): void
    {
        $now = time();
        $old = $this->createFile('aiscan_old.pdf', $now - 2 * TmpFileCleaner::MAX_AGE_SECONDS);
        $recent = $this->createFile('aiscan_recent.pdf', $now - 60);
        $hidden = $this->createFile('.htaccess', $now - 2 * TmpFileCleaner::MAX_AGE_SECONDS);

        self::assertSame(1, TmpFileCleaner::clean(TmpFileCleaner::MAX_AGE_SECONDS, $this->dir, $now));
        self::assertFileDoesNotExist($old);
        self::assertFileExists($recent);
        self::assertFileExists($hidden);
    }

    public function testMissingDirectoryIsIgnored(): void
    {
        self::assertSame(0, TmpFileCleaner::clean(60, $this->dir . '/missing'));
    }

    public function testCronCleansThePluginTmpDirectory(): void
    {
        $dir = TmpFileCleaner::tmpDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $old = $dir . '/aiscan_cron_test_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($old, 'x');
        touch($old, time() - 2 * TmpFileCleaner::MAX_AGE_SECONDS);

        try {
            (new Cron('AiScan'))->run();
            self::assertFileDoesNotExist($old);
        } finally {
            if (is_file($old)) {
                unlink($old);
            }
            $job = new CronJob();
            $where = [Where::eq('jobname', 'aiscan-clean-tmp'), Where::eq('pluginname', 'AiScan')];
            if ($job->loadWhere($where)) {
                $job->delete();
            }
        }
    }

    private function createFile(string $name, int $mtime): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, 'x');
        touch($path, $mtime);
        return $path;
    }
}
