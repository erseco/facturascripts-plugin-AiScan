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

use FacturaScripts\Plugins\AiScan\Controller\AiScanInvoice;
use PHPUnit\Framework\TestCase;

final class AiScanInvoiceControllerTest extends TestCase
{
    public function testNormalizeUploadedFilesKeepsSingleUploadShape(): void
    {
        $controller = $this->buildController();
        $result = $this->callNormalizeUploadedFiles($controller, [
            'error' => UPLOAD_ERR_OK,
            'name' => 'invoice.pdf',
            'size' => 123,
            'tmp_name' => '/tmp/php123',
            'type' => 'application/pdf',
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('invoice.pdf', $result[0]['name']);
        $this->assertSame('/tmp/php123', $result[0]['tmp_name']);
    }

    public function testNormalizeUploadedFilesExpandsMultipleUploadShape(): void
    {
        $controller = $this->buildController();
        $result = $this->callNormalizeUploadedFiles($controller, [
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'name' => ['first.pdf', 'second.png'],
            'size' => [111, 222],
            'tmp_name' => ['/tmp/php111', '/tmp/php222'],
            'type' => ['application/pdf', 'image/png'],
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('first.pdf', $result[0]['name']);
        $this->assertSame('second.png', $result[1]['name']);
        $this->assertSame('image/png', $result[1]['type']);
    }

    public function testResolveMimeTypeFallsBackToExtensionForOctetStream(): void
    {
        $controller = $this->buildController();
        $tmpFile = tempnam(sys_get_temp_dir(), 'aiscan-octet-');
        if (false === $tmpFile) {
            self::fail('Failed to create temporary file for MIME fallback test.');
        }

        file_put_contents($tmpFile, random_bytes(32));

        try {
            $result = $this->callResolveMimeType($controller, $tmpFile, 'pdf');
            $this->assertSame('application/pdf', $result);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    private function buildController(): AiScanInvoice
    {
        $reflection = new \ReflectionClass(AiScanInvoice::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    private function callNormalizeUploadedFiles(AiScanInvoice $controller, array $files): array
    {
        $method = new \ReflectionMethod(AiScanInvoice::class, 'normalizeUploadedFiles');
        $method->setAccessible(true);
        return $method->invoke($controller, $files);
    }

    private function callResolveMimeType(AiScanInvoice $controller, string $tmpName, string $extension): string
    {
        $method = new \ReflectionMethod(AiScanInvoice::class, 'resolveMimeType');
        $method->setAccessible(true);
        return $method->invoke($controller, $tmpName, $extension);
    }
}
