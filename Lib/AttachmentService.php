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

        $attachedFile = new AttachedFile();
        $attachedFile->path = 'aiscan_tmp/' . $tmpFile;
        if (false === $attachedFile->save()) {
            return;
        }

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
}
