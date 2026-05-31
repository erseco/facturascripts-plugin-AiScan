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

use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\AttachedFile;
use FacturaScripts\Dinamic\Model\AttachedFileRelation;
use FacturaScripts\Dinamic\Model\FacturaProveedor;

class AttachmentService
{
    public function attachTemporaryFile(FacturaProveedor $invoice, array $uploadData): void
    {
        $tmpFile = basename((string) ($uploadData['tmp_file'] ?? ''));
        if (empty($tmpFile) || false === preg_match('/^[a-zA-Z0-9_\-]+\.[a-zA-Z0-9]+$/', $tmpFile)) {
            return;
        }

        $tmpDir = realpath(FS_FOLDER . '/MyFiles/aiscan_tmp');
        $tmpPath = realpath(FS_FOLDER . '/MyFiles/aiscan_tmp/' . $tmpFile);
        $prefix = false === $tmpDir ? '' : rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (
            false === $tmpDir
            || false === $tmpPath
            || false === str_starts_with($tmpPath, $prefix)
            || false === is_file($tmpPath)
        ) {
            return;
        }

        $tmpFile = $this->moveTemporaryFileToMyFilesRoot($tmpPath, $tmpFile);
        if (empty($tmpFile)) {
            Tools::log()->warning('AiScan could not move temporary attachment into MyFiles root.');
            return;
        }

        $attachedFile = new AttachedFile();
        $attachedFile->path = $tmpFile;
        if (false === $attachedFile->save()) {
            $fullPath = FS_FOLDER . '/MyFiles/' . $tmpFile;
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
            return;
        }

        $this->applyOriginalFileName($attachedFile, (string) ($uploadData['original_name'] ?? ''));

        $relation = new AttachedFileRelation();
        $relation->idfile = $attachedFile->idfile;
        $relation->model = 'FacturaProveedor';
        $relation->modelid = (int) $invoice->idfactura;
        $relation->modelcode = (string) $invoice->idfactura;
        $relation->nick = Session::get('user')?->nick ?? $invoice->nick;
        $relation->observations = Tools::lang()->trans('aiscan-scanned-source-invoice');

        if (false === $relation->save()) {
            $attachedFile->delete();
        }
    }

    private function applyOriginalFileName(AttachedFile $attachedFile, string $originalName): void
    {
        if ('' === trim($originalName)) {
            return;
        }

        $safeName = $this->sanitizeStoredFileName($originalName);
        if (empty($safeName) || $attachedFile->filename === $safeName) {
            return;
        }

        $attachedFile->filename = $safeName;
        $attachedFile->save();
    }

    private function moveTemporaryFileToMyFilesRoot(string $tmpPath, string $tmpFile): string
    {
        $destDir = FS_FOLDER . '/MyFiles';
        $extension = strtolower(pathinfo($tmpFile, PATHINFO_EXTENSION));
        $baseName = pathinfo($tmpFile, PATHINFO_FILENAME);
        $destFile = $tmpFile;
        $counter = 1;

        while (file_exists($destDir . '/' . $destFile)) {
            $destFile = $baseName . '_' . $counter . (!empty($extension) ? '.' . $extension : '');
            ++$counter;
        }

        $destPath = $destDir . '/' . $destFile;
        if (rename($tmpPath, $destPath)) {
            return $destFile;
        }

        Tools::log()->warning('AiScan could not stage attachment file.', [
            '%source%' => $tmpPath,
            '%destination%' => $destPath,
        ]);
        return '';
    }

    private function sanitizeStoredFileName(string $originalName): string
    {
        $baseName = basename(trim($originalName));
        if (empty($baseName)) {
            return '';
        }

        return (string) preg_replace('/[^a-zA-Z0-9_\-.]/', '-', $baseName);
    }
}
