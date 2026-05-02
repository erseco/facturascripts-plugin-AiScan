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

use FacturaScripts\Dinamic\Model\AttachedFile;
use FacturaScripts\Plugins\AiScan\Lib\AttachmentService;
use PHPUnit\Framework\TestCase;

final class AttachmentServiceTest extends TestCase
{
    private AttachmentService $service;

    protected function setUp(): void
    {
        $this->service = new AttachmentService();
        @mkdir(FS_FOLDER . '/MyFiles/aiscan_tmp', 0777, true);
    }

    protected function tearDown(): void
    {
        $paths = [
            FS_FOLDER . '/MyFiles/aiscan_tmp/aiscan_invoice_test.jpg',
            FS_FOLDER . '/MyFiles/aiscan_invoice_test.jpg',
            FS_FOLDER . '/MyFiles/aiscan_invoice_test_1.jpg',
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testMoveTemporaryFileToMyFilesRootAvoidsCollisions(): void
    {
        $tmpPath = FS_FOLDER . '/MyFiles/aiscan_tmp/aiscan_invoice_test.jpg';
        $existingPath = FS_FOLDER . '/MyFiles/aiscan_invoice_test.jpg';

        file_put_contents($tmpPath, 'tmp');
        file_put_contents($existingPath, 'existing');

        $result = $this->invokeMethod('moveTemporaryFileToMyFilesRoot', [$tmpPath, 'aiscan_invoice_test.jpg']);

        $this->assertSame('aiscan_invoice_test_1.jpg', $result);
        $this->assertFileDoesNotExist($tmpPath);
        $this->assertFileExists(FS_FOLDER . '/MyFiles/' . $result);
    }

    public function testSanitizeStoredFileNameUsesSafeBasename(): void
    {
        $result = $this->invokeMethod('sanitizeStoredFileName', ['nested/path/scan 14.jpg']);

        $this->assertSame('scan-14.jpg', $result);
    }

    public function testApplyOriginalFileNamePersistsFriendlyName(): void
    {
        $attachedFile = $this->getMockBuilder(AttachedFile::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();

        $attachedFile->filename = 'aiscan_invoice_test.jpg';
        $attachedFile->expects($this->once())
            ->method('save')
            ->willReturn(true);

        $this->invokeMethod('applyOriginalFileName', [$attachedFile, 'Factura original 14.JPG']);

        $this->assertSame('Factura-original-14.JPG', $attachedFile->filename);
    }

    private function invokeMethod(string $methodName, array $args): mixed
    {
        $method = new \ReflectionMethod(AttachmentService::class, $methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->service, $args);
    }
}
