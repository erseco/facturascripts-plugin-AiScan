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

use FacturaScripts\Plugins\AiScan\Lib\ImageToPdfConverter;
use PHPUnit\Framework\TestCase;

/**
 * Issue #80: wrap an uploaded invoice photo in a one-page PDF.
 */
final class ImageToPdfConverterTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testConvertJpegProducesPdfDocument(): void
    {
        $source = $this->createJpeg(80, 120);
        $converter = new ImageToPdfConverter();

        $result = $converter->convert($source, 'image/jpeg');

        $this->assertSame('application/pdf', $result['mime']);
        $this->assertStringEndsWith('.pdf', $result['path']);
        $this->assertFileExists($result['path']);
        $this->tempFiles[] = $result['path'];
        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($result['path'], false, null, 0, 8));
        $this->assertGreaterThan(filesize($source), filesize($result['path']));
    }

    public function testConvertLeavesPdfUnchanged(): void
    {
        $source = $this->tempFile('source.pdf');
        file_put_contents($source, "%PDF-1.4\n% already a pdf");

        $result = (new ImageToPdfConverter())->convert($source, 'application/pdf');

        $this->assertSame($source, $result['path']);
        $this->assertSame('application/pdf', $result['mime']);
    }

    public function testIsConvertibleImage(): void
    {
        $converter = new ImageToPdfConverter();
        $this->assertTrue($converter->isConvertibleImage('image/jpeg'));
        $this->assertTrue($converter->isConvertibleImage('image/png'));
        $this->assertTrue($converter->isConvertibleImage('image/webp'));
        $this->assertFalse($converter->isConvertibleImage('application/pdf'));
        $this->assertFalse($converter->isConvertibleImage('text/plain'));
    }

    public function testConvertReplacesExtensionInSuggestedName(): void
    {
        $source = $this->createJpeg(16, 16);
        $result = (new ImageToPdfConverter())->convert($source, 'image/jpeg', 'Factura Leroy.jpg');

        $this->assertSame('Factura Leroy.pdf', $result['original_name']);
        $this->tempFiles[] = $result['path'];
    }

    private function createJpeg(int $width, int $height): string
    {
        $path = $this->tempFile('source.jpg');
        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, $width, $height, $white);
        imagejpeg($image, $path, 90);

        return $path;
    }

    private function tempFile(string $name): string
    {
        $path = sys_get_temp_dir() . '/aiscan-' . uniqid('', true) . '-' . $name;
        $this->tempFiles[] = $path;
        return $path;
    }
}
