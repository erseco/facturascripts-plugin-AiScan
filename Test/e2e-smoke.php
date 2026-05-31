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

/**
 * Non-interactive end-to-end smoke test: runs the real upload -> AI extraction ->
 * supplier match -> map -> import path against the configured AI provider, using
 * the sample invoices in Test/fixtures/. Intended to be run INSIDE the dev
 * container against a throwaway database (it creates suppliers and invoices).
 *
 * Usage (from the plugin root):
 *   docker compose cp Test/e2e-smoke.php facturascripts:/tmp/
 *   docker compose exec -T facturascripts php84 /tmp/e2e-smoke.php [fixture] [provider]
 *
 * Examples:
 *   php84 /tmp/e2e-smoke.php                 # all default fixtures, default provider
 *   php84 /tmp/e2e-smoke.php F-2025-007.jpg  # one fixture
 *   php84 /tmp/e2e-smoke.php F-2025-004.pdf gemini
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('FS_FOLDER', '/var/www/html');
require_once FS_FOLDER . '/vendor/autoload.php';
require_once FS_FOLDER . '/config.php';

use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\AiScan\Lib\ExtractionService;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use FacturaScripts\Plugins\AiScan\Lib\SupplierMatcher;

Kernel::init();

$fixturesDir = FS_FOLDER . '/Plugins/AiScan/Test/fixtures';
$tmpDir = FS_FOLDER . '/MyFiles/aiscan_tmp';
@mkdir($tmpDir, 0777, true);

$mimeByExt = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];

$argFixture = $argv[1] ?? '';
$provider = $argv[2] ?? null; // null = plugin default provider

$cases = $argFixture !== ''
    ? [$argFixture]
    : ['F-2025-007.jpg', 'F-2025-004.pdf', 'F-2025-005.pdf', 'F-2025-006.pdf'];

$failures = 0;
foreach ($cases as $file) {
    echo "\n================= {$file} =================\n";
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = $mimeByExt[$ext] ?? 'application/octet-stream';

    $source = $fixturesDir . '/' . $file;
    if (!is_file($source)) {
        echo "MISSING fixture: {$source}\n";
        $failures++;
        continue;
    }

    // Stage the file like handleUpload does (keep a real extension).
    $tmpName = 'aiscan_e2e_' . preg_replace('/[^a-z0-9]/i', '_', pathinfo($file, PATHINFO_FILENAME)) . '.' . $ext;
    copy($source, $tmpDir . '/' . $tmpName);

    try {
        $extracted = (new ExtractionService())->extractFromFile($tmpDir . '/' . $tmpName, $mime, $provider, 'lines');
    } catch (\Throwable $e) {
        echo 'EXTRACTION ERROR: ' . $e->getMessage() . "\n";
        $failures++;
        continue;
    }

    $supplier = $extracted['supplier']['name'] ?? '(none)';
    $total = $extracted['invoice']['total'] ?? '(none)';
    $lines = count($extracted['lines'] ?? []);
    echo "EXTRACT: supplier='{$supplier}' total={$total} lines={$lines}\n";

    $match = (new SupplierMatcher())->findMatch($extracted['supplier'] ?? []);
    $extracted['supplier']['match_status'] = $match['match_status'];
    if (!empty($match['supplier'])) {
        $extracted['supplier']['matched_supplier_id'] = $match['supplier']->codproveedor;
    } else {
        // Simulate the UI choosing to create the supplier when not matched.
        $extracted['supplier']['create_if_missing'] = true;
    }

    // Attach the uploaded source file (exercises the attachment flow).
    $extracted['_upload'] = ['tmp_file' => $tmpName, 'original_name' => $file];

    $result = (new InvoiceMapper())->mapToInvoice($extracted, null, 'lines', true);
    if (!$result['success']) {
        echo 'IMPORT FAILED: ' . implode('; ', $result['errors']) . "\n";
        $failures++;
        continue;
    }

    $invoice = new \FacturaScripts\Dinamic\Model\FacturaProveedor();
    $invoice->loadFromCode($result['invoice_id']);
    echo "IMPORT OK: {$invoice->codigo} proveedor='{$invoice->nombre}' total={$invoice->total}\n";
    foreach ($result['warnings'] as $warning) {
        echo "  warning: {$warning}\n";
    }

    $relations = (new \FacturaScripts\Dinamic\Model\AttachedFileRelation())->all([
        Where::eq('model', 'FacturaProveedor'),
        Where::eq('modelid', $invoice->idfactura),
    ]);
    foreach ($relations as $relation) {
        $attached = new \FacturaScripts\Dinamic\Model\AttachedFile();
        $attached->loadFromCode($relation->idfile);
        $state = is_file(FS_FOLDER . '/' . $attached->path) ? 'exists' : 'MISSING';
        echo "  attachment: {$attached->path} ({$attached->mimetype}, {$state})\n";
    }
}

echo "\n" . ($failures === 0 ? "SMOKE OK\n" : "SMOKE FAILURES: {$failures}\n");
exit($failures === 0 ? 0 : 1);
