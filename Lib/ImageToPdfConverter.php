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

namespace FacturaScripts\Plugins\AiScan\Lib;

use RuntimeException;

/**
 * Wraps a photo in a one-page PDF so the source can be stored and shared as PDF.
 * This does not deskew or crop the image; it only changes the container.
 */
class ImageToPdfConverter
{
    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    public function isConvertibleImage(string $mime): bool
    {
        return in_array(strtolower($mime), self::IMAGE_MIMES, true);
    }

    /**
     * @return array{path: string, mime: string, original_name: string}
     */
    public function convert(string $path, string $mime, string $originalName = ''): array
    {
        $displayName = $originalName !== '' ? $originalName : basename($path);
        if (!$this->isConvertibleImage($mime)) {
            return [
                'path' => $path,
                'mime' => $mime === 'image/jpg' ? 'image/jpeg' : $mime,
                'original_name' => $displayName,
            ];
        }

        if (!is_file($path)) {
            throw new RuntimeException('Image file not found: ' . $path);
        }

        $jpeg = $this->toJpeg($path, $mime);
        $size = getimagesizefromstring($jpeg);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new RuntimeException('Could not read image dimensions.');
        }

        $pdf = $this->buildPdf($jpeg, (int) $size[0], (int) $size[1]);
        $dest = $this->destinationPath($path);
        try {
            if (false === file_put_contents($dest, $pdf)) {
                throw new RuntimeException('Could not write PDF: ' . $dest);
            }
        } catch (\Throwable $exception) {
            if (is_file($dest)) {
                unlink($dest);
            }
            throw $exception;
        }

        return [
            'path' => $dest,
            'mime' => 'application/pdf',
            'original_name' => $this->pdfFileName($displayName),
        ];
    }

    public function pdfFileName(string $originalName): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'invoice';
        }

        return $base . '.pdf';
    }

    private function destinationPath(string $sourcePath): string
    {
        $dir = dirname($sourcePath);
        $base = pathinfo($sourcePath, PATHINFO_FILENAME);
        $dest = $dir . '/' . $base . '.pdf';
        $counter = 1;
        while (is_file($dest)) {
            $dest = $dir . '/' . $base . '_' . $counter . '.pdf';
            ++$counter;
        }

        return $dest;
    }

    private function toJpeg(string $path, string $mime): string
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Could not read image: ' . $path);
        }

        $isJpeg = in_array($mime, ['image/jpeg', 'image/jpg'], true);
        if (!function_exists('imagecreatefromstring')) {
            if ($isJpeg) {
                return $raw;
            }
            throw new RuntimeException('GD is required to convert images to PDF.');
        }

        $image = imagecreatefromstring($raw);
        if ($image === false) {
            throw new RuntimeException('Could not decode image: ' . $path);
        }

        if (function_exists('imagepalettetotruecolor') && !imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        if ($isJpeg) {
            $image = $this->applyExifOrientation($image, $raw);
        }

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        if ($jpeg === '') {
            throw new RuntimeException('Could not encode JPEG for PDF.');
        }

        return $jpeg;
    }

    /**
     * @param \GdImage|resource $image
     *
     * @return \GdImage|resource
     */
    private function applyExifOrientation($image, string $jpeg)
    {
        $orientation = $this->readJpegOrientation($jpeg);
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                return $image;
            case 3:
                return $this->rotateImage($image, 180);
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                return $image;
            case 5:
                imageflip($image, IMG_FLIP_VERTICAL);
                return $this->rotateImage($image, 270);
            case 6:
                return $this->rotateImage($image, 270);
            case 7:
                imageflip($image, IMG_FLIP_VERTICAL);
                return $this->rotateImage($image, 90);
            case 8:
                return $this->rotateImage($image, 90);
            default:
                return $image;
        }
    }

    /**
     * @param \GdImage|resource $image
     *
     * @return \GdImage|resource
     */
    private function rotateImage($image, int $degrees)
    {
        $rotated = imagerotate($image, $degrees, 0);
        return $rotated !== false ? $rotated : $image;
    }

    /**
     * Read EXIF Orientation from a JPEG APP1 segment without requiring ext-exif.
     */
    private function readJpegOrientation(string $jpeg): int
    {
        if (strlen($jpeg) < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
            return 1;
        }

        $offset = 2;
        $length = strlen($jpeg);
        while ($offset + 4 <= $length) {
            if ($jpeg[$offset] !== "\xFF") {
                break;
            }
            $marker = ord($jpeg[$offset + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $segmentLength = unpack('n', substr($jpeg, $offset + 2, 2));
            if ($segmentLength === false || $segmentLength[1] < 2) {
                break;
            }
            $size = $segmentLength[1];
            if ($marker === 0xE1) {
                $payload = substr($jpeg, $offset + 4, $size - 2);
                $orientation = $this->orientationFromExifPayload($payload);
                if ($orientation !== null) {
                    return $orientation;
                }
            }
            $offset += 2 + $size;
        }

        return 1;
    }

    private function orientationFromExifPayload(string $payload): ?int
    {
        if (!str_starts_with($payload, "Exif\0\0")) {
            return null;
        }

        $tiff = substr($payload, 6);
        if (strlen($tiff) < 8) {
            return null;
        }

        $endian = substr($tiff, 0, 2);
        $little = $endian === 'II';
        if (!$little && $endian !== 'MM') {
            return null;
        }

        $read16 = static function (string $data, int $pos) use ($little): int {
            $unpacked = unpack($little ? 'v' : 'n', substr($data, $pos, 2));
            return $unpacked === false ? 0 : (int) $unpacked[1];
        };
        $read32 = static function (string $data, int $pos) use ($little): int {
            $unpacked = unpack($little ? 'V' : 'N', substr($data, $pos, 4));
            return $unpacked === false ? 0 : (int) $unpacked[1];
        };

        $ifdOffset = $read32($tiff, 4);
        if ($ifdOffset + 2 > strlen($tiff)) {
            return null;
        }

        $count = $read16($tiff, $ifdOffset);
        for ($i = 0; $i < $count; ++$i) {
            $entry = $ifdOffset + 2 + ($i * 12);
            if ($entry + 12 > strlen($tiff)) {
                break;
            }
            if ($read16($tiff, $entry) !== 0x0112) {
                continue;
            }
            $value = $read16($tiff, $entry + 8);
            if ($value >= 1 && $value <= 8) {
                return $value;
            }
        }

        return null;
    }

    private function buildPdf(string $jpeg, int $imgWidth, int $imgHeight): string
    {
        $maxWidth = 595.28;
        $maxHeight = 841.89;
        $scale = min($maxWidth / $imgWidth, $maxHeight / $imgHeight);
        $pageW = round($imgWidth * $scale, 2);
        $pageH = round($imgHeight * $scale, 2);

        $content = sprintf("q\n%.2F 0 0 %.2F 0 0 cm\n/Im0 Do\nQ\n", $pageW, $pageH);
        $objects = [];
        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[] = sprintf(
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Contents 4 0 R"
            . " /Resources << /XObject << /Im0 5 0 R >> >> >>",
            $pageW,
            $pageH
        );
        $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        $objects[] = "<< /Type /XObject /Subtype /Image /Width " . $imgWidth
            . " /Height " . $imgHeight
            . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length "
            . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; ++$i) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF\n";

        return $pdf;
    }
}
