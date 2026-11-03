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

use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AttachedFile;
use FacturaScripts\Dinamic\Model\AttachedFileRelation;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Plugins\AiScan\Lib\AttachmentService;
use PHPUnit\Framework\TestCase;

final class AttachmentServiceTest extends TestCase
{
    private AttachmentService $service;

    public static function setUpBeforeClass(): void
    {
        // The Dinamic namespace is not built in the test environment, so alias
        // FacturaScripts\Dinamic\* to its Core counterpart on demand.
        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'FacturaScripts\\Dinamic\\')) {
                $coreClass = str_replace('\\Dinamic\\', '\\Core\\', $class);
                if (!class_exists($class, false) && class_exists($coreClass)) {
                    class_alias($coreClass, $class);
                }
            }
        }, true, true);
    }

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

    public function testResolveAttachmentDisplayNameUsesPdfWhenStoredFileIsPdf(): void
    {
        $result = $this->invokeMethod(
            'resolveAttachmentDisplayName',
            ['nombre factura.jpeg', 'aiscan_nombre-factura_abc123.pdf']
        );

        $this->assertSame('nombre factura.pdf', $result);
    }

    public function testResolveAttachmentDisplayNameKeepsPdfName(): void
    {
        $result = $this->invokeMethod(
            'resolveAttachmentDisplayName',
            ['factura.pdf', 'aiscan_factura_abc123.pdf']
        );

        $this->assertSame('factura.pdf', $result);
    }

    public function testFailedLinkDeletesTheNewAttachedFile(): void
    {
        $name = 'aiscan_link_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents(FS_FOLDER . '/MyFiles/aiscan_tmp/' . $name, '%PDF-1.4 link');
        $before = (new AttachedFile())->count();

        $this->withoutSessionUser(function () use ($name): void {
            $this->service->attachTemporaryFile($this->invoiceOfUnknownUser(), ['tmp_file' => $name]);
        });

        $this->assertSame($before, (new AttachedFile())->count());
    }

    public function testExistingFileSurvivesAFailedLink(): void
    {
        $staged = 'aiscan_link_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents(FS_FOLDER . '/MyFiles/' . $staged, '%PDF-1.4 link');
        $file = new AttachedFile();
        $file->path = $staged;
        $this->assertTrue($file->save());

        try {
            $this->withoutSessionUser(function () use ($file): void {
                $this->service->attachExistingFile($this->invoiceOfUnknownUser(), (int) $file->idfile);
            });

            $this->assertTrue((new AttachedFile())->load($file->idfile));
            $this->assertSame(0, (new AttachedFileRelation())->count([Where::eq('idfile', $file->idfile)]));
        } finally {
            $file->delete();
        }
    }

    public function testUnknownAttachedFileIsReportedAndNotLinked(): void
    {
        MiniLog::clear();
        $invoice = new FacturaProveedor();
        $invoice->idfactura = 999999999;

        $this->service->attachExistingFile($invoice, 999999999);

        $warnings = array_filter(
            MiniLog::read('', ['warning']),
            static fn (array $item): bool => ($item['context']['%idfile%'] ?? null) === 999999999
        );
        $this->assertCount(1, $warnings);
        $this->assertSame(0, (new AttachedFileRelation())->count([
            Where::eq('model', 'FacturaProveedor'),
            Where::eq('modelid', 999999999),
        ]));
    }

    /**
     * The relation's user must exist (foreign key), so this invoice makes
     * linking any file fail.
     */
    private function invoiceOfUnknownUser(): FacturaProveedor
    {
        $invoice = new FacturaProveedor();
        $invoice->idfactura = 999999999;
        $invoice->nick = 'ghost-' . bin2hex(random_bytes(4));

        return $invoice;
    }

    private function withoutSessionUser(callable $callback): void
    {
        $user = Session::get('user');
        Session::set('user', null);
        try {
            $callback();
        } finally {
            Session::set('user', $user);
        }
    }

    private function invokeMethod(string $methodName, array $args): mixed
    {
        $method = new \ReflectionMethod(AttachmentService::class, $methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->service, $args);
    }
}
