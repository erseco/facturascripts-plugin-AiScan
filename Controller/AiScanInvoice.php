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

namespace FacturaScripts\Plugins\AiScan\Controller;

use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\AssetManager;
use FacturaScripts\Plugins\AiScan\Lib\AiScanSettings;
use FacturaScripts\Plugins\AiScan\Lib\ExtractionService;
use FacturaScripts\Plugins\AiScan\Lib\HistoricalContextService;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use FacturaScripts\Plugins\AiScan\Lib\SupplierMatcher;
use FacturaScripts\Plugins\AiScan\Model\AiScanImportBatch;
use FacturaScripts\Plugins\AiScan\Model\AiScanImportDocument;
use FacturaScripts\Plugins\AiScan\Model\AiScanImportLine;
use FacturaScripts\Plugins\AiScan\Model\AiScanSupplierProduct;

class AiScanInvoice extends Controller
{
    private const EXTENSION_MIME_TYPES = [
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'purchases';
        $data['title'] = 'aiscan-page-title';
        $data['icon'] = 'fa-solid fa-wand-magic-sparkles';
        $data['showonmenu'] = true;
        return $data;
    }

    public function run(): void
    {
        parent::run();

        $action = $this->request()->get('action', '');

        if (empty($action)) {
            $this->loadPageAssets();
            $service = new ExtractionService();
            $impuesto = new \FacturaScripts\Dinamic\Model\Impuesto();
            $retencion = new \FacturaScripts\Dinamic\Model\Retencion();
            $this->view('AiScanInvoice.html.twig', [
                'availableProviders' => $service->getAvailableProviderNames(),
                'defaultProvider' => AiScanSettings::getDefaultProvider(),
                'taxTypes' => $impuesto->all([], ['iva' => 'ASC'], 0, 0),
                'withholdingTypes' => $retencion->all([], ['porcentaje' => 'ASC'], 0, 0),
            ]);
            return;
        }

        if (!$this->permissions->allowUpdate) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => Tools::lang()->trans('permission-denied')]);
            return;
        }

        header('Content-Type: application/json');

        try {
            switch ($action) {
                case 'upload':
                    $this->handleUpload();
                    break;
                case 'analyze':
                    $this->handleAnalyze();
                    break;
                case 'match-supplier':
                    $this->handleMatchSupplier();
                    break;
                case 'apply':
                    $this->handleApply();
                    break;
                case 'import-batch':
                    $this->handleImportBatch();
                    break;
                case 'get-text':
                    $this->handleGetText();
                    break;
                case 'search-suppliers':
                    $this->handleSearchSuppliers();
                    break;
                case 'create-supplier':
                    $this->handleCreateSupplier();
                    break;
                case 'search-products':
                    $this->handleSearchProducts();
                    break;
                case 'get-supplier-default-product':
                    $this->handleGetSupplierDefaultProduct();
                    break;
                case 'set-supplier-default-product':
                    $this->handleSetSupplierDefaultProduct();
                    break;
                case 'get-historical-context':
                    $this->handleGetHistoricalContext();
                    break;
                default:
                    http_response_code(400);
                    echo json_encode([
                        'error' => Tools::lang()->trans('aiscan-unknown-action', ['%action%' => (string) $action]),
                    ]);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            $message = $e->getMessage();
            echo json_encode(['error' => $message]);
            Tools::log()->error('AiScan error: ' . $e->getMessage());
        }
    }

    private function loadPageAssets(): void
    {
        $route = Tools::config('route');
        $v = '?v=' . $this->getPluginVersion();
        AssetManager::addCss($route . '/Plugins/AiScan/Assets/CSS/aiscan.css' . $v);
        AssetManager::addJs($route . '/Plugins/AiScan/Assets/JS/aiscan-workflow.js' . $v);
    }

    private function getPluginVersion(): string
    {
        $iniPath = FS_FOLDER . '/Plugins/AiScan/facturascripts.ini';
        if (file_exists($iniPath)) {
            $ini = parse_ini_file($iniPath);
            return $ini['version'] ?? '0';
        }
        return '0';
    }

    private function handleUpload(): void
    {
        $uploadedFiles = $this->getUploadedFiles();
        if (empty($uploadedFiles)) {
            http_response_code(400);
            echo json_encode(['error' => Tools::lang()->trans('aiscan-no-file-uploaded')]);
            return;
        }

        $storedFiles = [];
        $errors = [];

        foreach ($uploadedFiles as $index => $file) {
            try {
                $storedFiles[] = $this->storeUploadedFile($file, $index);
            } catch (\RuntimeException $e) {
                $errors[] = [
                    'client_index' => $index,
                    'error' => $e->getMessage(),
                    'name' => basename((string) ($file['name'] ?? '')),
                ];
            }
        }

        if (empty($storedFiles)) {
            http_response_code(422);
            echo json_encode([
                'error' => $errors[0]['error'] ?? Tools::lang()->trans('aiscan-no-file-uploaded'),
                'errors' => $errors,
            ]);
            return;
        }

        $service = new ExtractionService();
        echo json_encode($this->buildUploadResponse($storedFiles, $errors, $service));
    }

    private function getUploadedFiles(): array
    {
        if (isset($_FILES['invoice_files'])) {
            return $this->normalizeUploadedFiles($_FILES['invoice_files']);
        }

        if (isset($_FILES['invoice_file'])) {
            return $this->normalizeUploadedFiles($_FILES['invoice_file']);
        }

        return [];
    }

    private function normalizeUploadedFiles(array $files): array
    {
        if (!isset($files['name']) || !is_array($files['name'])) {
            return [$files];
        }

        $normalized = [];
        $count = count($files['name']);
        for ($index = 0; $index < $count; ++$index) {
            $normalized[] = [
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'name' => $files['name'][$index] ?? '',
                'size' => $files['size'][$index] ?? 0,
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'type' => $files['type'][$index] ?? '',
            ];
        }

        return $normalized;
    }

    private function storeUploadedFile(array $file, int $clientIndex): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(
                Tools::lang()->trans('aiscan-upload-error', ['%code%' => (string) $file['error']])
            );
        }

        $maxSizeBytes = AiScanSettings::getMaxUploadSizeMb() * 1024 * 1024;
        if ((int) $file['size'] > $maxSizeBytes) {
            throw new \RuntimeException(
                Tools::lang()->trans(
                    'aiscan-file-too-large',
                    ['%size%' => (string) AiScanSettings::getMaxUploadSizeMb()]
                )
            );
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, AiScanSettings::getAllowedExtensions(), true)) {
            throw new \RuntimeException(
                Tools::lang()->trans('aiscan-unsupported-file-extension', ['%extension%' => $extension])
            );
        }

        $mimeType = $this->resolveMimeType((string) $file['tmp_name'], $extension);
        if (!in_array($mimeType, array_values(self::EXTENSION_MIME_TYPES), true)) {
            throw new \RuntimeException(
                Tools::lang()->trans('aiscan-unsupported-file-type', ['%mimeType%' => (string) $mimeType])
            );
        }

        $tmpDir = FS_FOLDER . '/MyFiles/aiscan_tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0700, true);
        }

        $safeBaseName = $this->sanitizeBaseFilename((string) $file['name']);
        $tmpFilename = 'aiscan_' . $safeBaseName . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $tmpPath = $tmpDir . '/' . $tmpFilename;

        if (!move_uploaded_file((string) $file['tmp_name'], $tmpPath)) {
            throw new \RuntimeException(Tools::lang()->trans('aiscan-failed-to-store-uploaded-file'));
        }

        return [
            'client_index' => $clientIndex,
            'mime_type' => $mimeType,
            'original_name' => basename((string) $file['name']),
            'size' => (int) $file['size'],
            'tmp_file' => $tmpFilename,
        ];
    }

    private function resolveMimeType(string $tmpName, string $extension): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) $finfo->file($tmpName);

        $genericTypes = ['', 'application/octet-stream', 'text/plain'];
        if (in_array($mimeType, $genericTypes, true)) {
            return self::EXTENSION_MIME_TYPES[$extension] ?? $mimeType;
        }

        return $mimeType;
    }

    private function buildUploadResponse(array $storedFiles, array $errors, ExtractionService $service): array
    {
        $response = [
            'success' => true,
            'files' => $storedFiles,
            'errors' => $errors,
            'auto_scan' => AiScanSettings::isAutoScanEnabled(),
            'provider' => AiScanSettings::getDefaultProvider(),
            'available_providers' => $service->getAvailableProviderNames(),
            'extraction_prompt' => ExtractionService::getSystemPrompt(),
            'max_parallel_requests' => (int) AiScanSettings::get('max_parallel_requests', 5),
        ];

        if (count($storedFiles) === 1) {
            $response = array_merge($response, $storedFiles[0]);
        }

        return $response;
    }

    private function sanitizeBaseFilename(string $filename): string
    {
        $safeBaseName = preg_replace('/[^a-zA-Z0-9_-]/', '-', pathinfo($filename, PATHINFO_FILENAME));
        $safeBaseName = trim((string) $safeBaseName, '-');
        return substr($safeBaseName ?: 'invoice', 0, 40);
    }

    private function handleAnalyze(): void
    {
        $tmpFile = $this->request()->get('tmp_file', '');
        $mimeType = $this->request()->get('mime_type', '');
        $importMode = $this->request()->get('import_mode', 'lines');
        $useHistory = $this->request()->get('use_history', '0');
        $supplierId = $this->request()->get('supplier_id', '');

        if (empty($tmpFile)) {
            http_response_code(400);
            echo json_encode(['error' => Tools::lang()->trans('aiscan-no-file-specified')]);
            return;
        }

        $tmpFile = basename($tmpFile);
        if (!preg_match('/^[a-zA-Z0-9_\-]+\.[a-zA-Z0-9]+$/', $tmpFile)) {
            http_response_code(400);
            echo json_encode(['error' => Tools::lang()->trans('aiscan-invalid-file-name')]);
            return;
        }
        $tmpPath = FS_FOLDER . '/MyFiles/aiscan_tmp/' . $tmpFile;

        if (!file_exists($tmpPath)) {
            http_response_code(404);
            echo json_encode(['error' => Tools::lang()->trans('aiscan-temporary-file-not-found')]);
            return;
        }

        if (!AiScanSettings::isEnabled()) {
            http_response_code(503);
            echo json_encode(['error' => Tools::lang()->trans('aiscan-plugin-disabled')]);
            return;
        }

        $historicalContext = '';
        if ($useHistory === '1' && !empty($supplierId)) {
            $contextService = new HistoricalContextService();
            $context = $contextService->buildContext($supplierId);
            $historicalContext = $contextService->formatForPrompt($context);
        }

        $provider = $this->request()->get('provider', '');
        $service = new ExtractionService();
        $extracted = $service->extractFromFile(
            $tmpPath,
            $mimeType,
            $provider ?: null,
            $importMode,
            $historicalContext
        );

        // Multi-invoice: the AI returned several invoices from one document
        if (!empty($extracted['_multi_invoice'])) {
            $invoices = [];
            foreach ($extracted['invoices'] as $single) {
                $invoices[] = $this->enrichExtractedData($single);
            }
            echo json_encode([
                'success' => true,
                '_multi_invoice' => true,
                'invoices' => $invoices,
            ]);
            return;
        }

        $extracted = $this->enrichExtractedData($extracted);

        echo json_encode(['success' => true, 'data' => $extracted]);
    }

    /**
     * Enrich a single extracted invoice with supplier matching and duplicate checks.
     */
    private function enrichExtractedData(array $extracted): array
    {
        if (!empty($extracted['supplier'])) {
            $matcher = new SupplierMatcher();
            $matchResult = $matcher->findMatch($extracted['supplier']);
            $extracted['supplier']['match_status'] = $matchResult['match_status'];
            if ($matchResult['supplier']) {
                $extracted['supplier']['matched_supplier_id'] = $matchResult['supplier']->codproveedor;
                $extracted['supplier']['matched_name'] = $matchResult['supplier']->nombre;
            }
            if (!empty($matchResult['candidates'])) {
                $extracted['supplier']['candidates'] = array_map(
                    [self::class, 'supplierToArray'],
                    $matchResult['candidates']
                );
            }
        }

        // Check for duplicate invoice already in FacturaScripts
        $duplicateWarning = $this->checkDuplicateInvoice($extracted);
        if ($duplicateWarning) {
            $extracted['_duplicate'] = $duplicateWarning;
            $extracted['_validation_errors'][] = $duplicateWarning['message'];
        }

        return $extracted;
    }

    private function handleMatchSupplier(): void
    {
        $name = $this->request()->get('name', '');
        $taxId = $this->request()->get('tax_id', '');

        $matcher = new SupplierMatcher();
        $matchResult = $matcher->findMatch(['name' => $name, 'tax_id' => $taxId]);

        $response = ['match_status' => $matchResult['match_status']];
        if ($matchResult['supplier']) {
            $response['supplier'] = self::supplierToArray($matchResult['supplier']);
        }
        if (!empty($matchResult['candidates'])) {
            $response['candidates'] = array_map(
                [self::class, 'supplierToArray'],
                $matchResult['candidates']
            );
        }

        echo json_encode($response);
    }

    private static function supplierToArray(object $s): array
    {
        return [
            'id' => $s->codproveedor,
            'name' => $s->nombre,
            'tax_id' => $s->cifnif,
        ];
    }

    private function handleSearchSuppliers(): void
    {
        $query = trim($this->request()->get('query', ''));
        if (strlen($query) < 2) {
            echo json_encode(['results' => []]);
            return;
        }

        $supplier = new \FacturaScripts\Dinamic\Model\Proveedor();
        $where = [
            \FacturaScripts\Core\Where::like('nombre', '%' . $query . '%'),
        ];
        $results = $supplier->all($where, ['nombre' => 'ASC'], 0, 20);

        if (preg_match('/^[A-Z0-9]/i', $query)) {
            $whereCif = [
                \FacturaScripts\Core\Where::like('cifnif', '%' . $query . '%'),
            ];
            $byCif = $supplier->all($whereCif, [], 0, 10);
            $existingIds = array_map(fn ($s) => $s->codproveedor, $results);
            foreach ($byCif as $s) {
                if (!in_array($s->codproveedor, $existingIds)) {
                    $results[] = $s;
                }
            }
        }

        $items = array_map(fn ($s) => [
            'id' => $s->codproveedor,
            'name' => $s->nombre,
            'tax_id' => $s->cifnif,
        ], array_slice($results, 0, 20));

        echo json_encode(['results' => $items]);
    }

    private function handleCreateSupplier(): void
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => Tools::lang()->trans('aiscan-invalid-json-payload')]);
            return;
        }

        $name = trim((string) ($data['name'] ?? ''));
        if (empty($name)) {
            http_response_code(400);
            echo json_encode([
                'error' => Tools::lang()->trans('aiscan-supplier-name-required'),
            ]);
            return;
        }

        $supplier = new \FacturaScripts\Dinamic\Model\Proveedor();
        $supplier->nombre = $name;
        $supplier->razonsocial = $name;
        $supplier->cifnif = trim((string) ($data['tax_id'] ?? ''));
        $supplier->email = trim((string) ($data['email'] ?? ''));
        $supplier->telefono1 = trim((string) ($data['phone'] ?? ''));
        $supplier->personafisica = false;

        if ($supplier->save()) {
            echo json_encode([
                'success' => true,
                'supplier' => [
                    'id' => $supplier->codproveedor,
                    'name' => $supplier->nombre,
                    'tax_id' => $supplier->cifnif,
                ],
            ]);
        } else {
            http_response_code(422);
            echo json_encode([
                'error' => Tools::lang()->trans('aiscan-supplier-create-error'),
            ]);
        }
    }

    private function handleSearchProducts(): void
    {
        $query = trim($this->request()->get('query', ''));
        if (strlen($query) < 2) {
            echo json_encode(['results' => []]);
            return;
        }

        $variant = new \FacturaScripts\Dinamic\Model\Variante();
        $results = $variant->codeModelSearch($query, 'referencia');

        $items = array_map(fn ($item) => [
            'referencia' => $item->code,
            'description' => $item->description,
        ], array_slice($results, 0, 20));

        echo json_encode(['results' => $items]);
    }

    private function handleGetSupplierDefaultProduct(): void
    {
        $codproveedor = $this->request()->get('codproveedor', '');
        if (empty($codproveedor)) {
            echo json_encode(['found' => false]);
            return;
        }

        $mapping = AiScanSupplierProduct::getForSupplier($codproveedor);
        if ($mapping) {
            echo json_encode([
                'found' => true,
                'referencia' => $mapping->referencia,
                'description' => $mapping->description,
            ]);
        } else {
            echo json_encode(['found' => false]);
        }
    }

    private function handleSetSupplierDefaultProduct(): void
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => Tools::lang()->trans('aiscan-invalid-json-payload')]);
            return;
        }

        $codproveedor = trim((string) ($data['codproveedor'] ?? ''));
        $referencia = trim((string) ($data['referencia'] ?? ''));

        if (empty($codproveedor) || empty($referencia)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing codproveedor or referencia']);
            return;
        }

        $saved = AiScanSupplierProduct::setForSupplier(
            $codproveedor,
            $referencia,
            trim((string) ($data['description'] ?? ''))
        );

        echo json_encode(['success' => $saved]);
    }

    private function handleGetHistoricalContext(): void
    {
        $codproveedor = $this->request()->get('codproveedor', '');
        if (empty($codproveedor)) {
            echo json_encode(['context' => []]);
            return;
        }

        $service = new HistoricalContextService();
        $context = $service->buildContext($codproveedor);
        echo json_encode(['context' => $context]);
    }

    private function handleGetText(): void
    {
        $tmpFile = $this->request()->get('tmp_file', '');

        if (empty($tmpFile)) {
            http_response_code(400);
            echo json_encode(['error' => Tools::lang()->trans('aiscan-no-file-specified')]);
            return;
        }

        $tmpFile = basename($tmpFile);
        if (!preg_match('/^[a-zA-Z0-9_\-]+\.[a-zA-Z0-9]+$/', $tmpFile)) {
            http_response_code(400);
            echo json_encode(['error' => Tools::lang()->trans('aiscan-invalid-file-name')]);
            return;
        }
        $tmpPath = FS_FOLDER . '/MyFiles/aiscan_tmp/' . $tmpFile;

        if (!file_exists($tmpPath)) {
            http_response_code(404);
            echo json_encode(['error' => Tools::lang()->trans('aiscan-temporary-file-not-found')]);
            return;
        }

        $extension = strtolower(pathinfo($tmpPath, PATHINFO_EXTENSION));
        $text = '';

        if ($extension === 'pdf') {
            $text = ExtractionService::extractPdfText($tmpPath);
        } else {
            $text = Tools::lang()->trans('aiscan-image-text-extraction-requires-provider');
        }

        echo json_encode(['success' => true, 'text' => $text]);
    }

    private function handleApply(): void
    {
        $invoiceId = $this->request()->get('invoice_id', '');
        $invoiceId = $invoiceId !== '' ? (int) $invoiceId : null;
        $importMode = $this->request()->get('import_mode', 'lines');

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => Tools::lang()->trans('aiscan-invalid-json-payload')]);
            return;
        }

        $mapper = new InvoiceMapper();
        $result = $mapper->mapToInvoice($data, $invoiceId, $importMode);

        if (!$result['success']) {
            http_response_code(422);
            echo json_encode(['error' => implode('; ', $result['errors'])]);
            return;
        }

        echo json_encode(['success' => true, 'invoice_id' => $result['invoice_id']]);
    }

    private function handleImportBatch(): void
    {
        $body = file_get_contents('php://input');
        $batch = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($batch)) {
            http_response_code(400);
            echo json_encode(['error' => Tools::lang()->trans('aiscan-invalid-json-payload')]);
            return;
        }

        $documents = $batch['documents'] ?? [];
        $importMode = $batch['import_mode'] ?? 'lines';
        $results = [];
        $mapper = new InvoiceMapper();

        // Create history batch record
        $historyBatch = new AiScanImportBatch();
        $historyBatch->importmode = $importMode;
        $historyBatch->provider = $batch['provider'] ?? null;
        $historyBatch->totaldocuments = count($documents);
        $historyBatch->save();

        $imported = 0;
        $discarded = 0;
        $failed = 0;

        foreach ($documents as $index => $doc) {
            $status = $doc['status'] ?? 'pending';
            $extracted = $doc['extracted_data'] ?? [];
            $invoice = $extracted['invoice'] ?? [];
            $supplier = $extracted['supplier'] ?? [];
            $lines = $extracted['lines'] ?? [];

            if ($status === 'discarded') {
                $this->persistHistoryDocument(
                    $historyBatch->id,
                    $doc,
                    $invoice,
                    $supplier,
                    'discarded',
                    null,
                    null,
                    null,
                    $lines
                );
                $discarded++;
                $results[] = [
                    'index' => $index,
                    'status' => 'skipped',
                    'original_name' => $doc['original_name'] ?? '',
                ];
                continue;
            }

            if (empty($extracted)) {
                $this->persistHistoryDocument(
                    $historyBatch->id,
                    $doc,
                    $invoice,
                    $supplier,
                    'failed',
                    null,
                    null,
                    'No extracted data',
                    $lines
                );
                $failed++;
                $results[] = [
                    'index' => $index,
                    'status' => 'error',
                    'error' => 'No extracted data',
                    'original_name' => $doc['original_name'] ?? '',
                ];
                continue;
            }

            $extracted['_upload'] = [
                'tmp_file' => $doc['tmp_file'] ?? '',
                'mime_type' => $doc['mime_type'] ?? '',
                'original_name' => $doc['original_name'] ?? '',
            ];

            $docImportMode = $doc['import_mode'] ?? $importMode;
            $result = $mapper->mapToInvoice($extracted, null, $docImportMode);

            if ($result['success']) {
                $invoiceCode = $this->resolveInvoiceCode($result['invoice_id']);
                $this->persistHistoryDocument(
                    $historyBatch->id,
                    $doc,
                    $invoice,
                    $supplier,
                    'imported',
                    $result['invoice_id'],
                    $invoiceCode,
                    null,
                    $lines
                );
                $imported++;
            } else {
                $errorMsg = implode('; ', $result['errors']);
                $this->persistHistoryDocument(
                    $historyBatch->id,
                    $doc,
                    $invoice,
                    $supplier,
                    'failed',
                    null,
                    null,
                    $errorMsg,
                    $lines
                );
                $failed++;
            }

            $results[] = [
                'index' => $index,
                'status' => $result['success'] ? 'imported' : 'error',
                'invoice_id' => $result['invoice_id'],
                'error' => $result['success'] ? null : implode('; ', $result['errors']),
                'original_name' => $doc['original_name'] ?? '',
            ];
        }

        // Update batch counters
        $historyBatch->importedcount = $imported;
        $historyBatch->discardedcount = $discarded;
        $historyBatch->failedcount = $failed;
        $historyBatch->save();

        echo json_encode([
            'success' => true,
            'results' => $results,
            'batch_id' => $historyBatch->id,
        ]);
    }

    private function persistHistoryDocument(
        int $batchId,
        array $doc,
        array $invoice,
        array $supplier,
        string $status,
        ?int $invoiceId,
        ?string $invoiceCode,
        ?string $error,
        array $lines
    ): void {
        $histDoc = new AiScanImportDocument();
        $histDoc->idbatch = $batchId;
        $histDoc->originalname = $doc['original_name'] ?? '';
        $histDoc->codproveedor = $supplier['matched_supplier_id'] ?? null;
        $histDoc->suppliername = $supplier['name'] ?? null;
        $histDoc->numproveedor = $invoice['number'] ?? null;
        $histDoc->fecha = $invoice['issue_date'] ?? null;
        $histDoc->coddivisa = $invoice['currency'] ?? 'EUR';
        $histDoc->neto = (float) ($invoice['subtotal'] ?? 0);
        $histDoc->totaliva = (float) ($invoice['tax_amount'] ?? 0);
        $histDoc->total = (float) ($invoice['total'] ?? 0);
        $histDoc->status = $status;
        $histDoc->idfactura = $invoiceId;
        $histDoc->codigofactura = $invoiceCode;
        $histDoc->errormessage = $error;

        if (!$histDoc->save()) {
            return;
        }

        foreach ($lines as $idx => $line) {
            $histLine = new AiScanImportLine();
            $histLine->iddocument = $histDoc->id;
            $histLine->sortorder = $idx + 1;
            $histLine->descripcion = trim((string) ($line['description'] ?? ''));
            $histLine->cantidad = (float) ($line['quantity'] ?? 1);
            $histLine->pvpunitario = (float) ($line['unit_price'] ?? 0);
            $histLine->dtopor = (float) ($line['discount'] ?? 0);
            $histLine->iva = (float) ($line['tax_rate'] ?? 0);
            $histLine->pvptotal = $histLine->cantidad * $histLine->pvpunitario;
            $histLine->referencia = $line['sku'] ?? null;
            $histLine->save();
        }
    }

    private function checkDuplicateInvoice(array $extracted): ?array
    {
        $invoice = $extracted['invoice'] ?? [];
        $supplier = $extracted['supplier'] ?? [];

        $numproveedor = trim((string) ($invoice['number'] ?? ''));
        $fecha = trim((string) ($invoice['issue_date'] ?? ''));
        $codproveedor = $supplier['matched_supplier_id'] ?? '';
        $total = (float) ($invoice['total'] ?? 0);

        if (empty($numproveedor) || empty($codproveedor)) {
            return null;
        }

        $factura = new \FacturaScripts\Dinamic\Model\FacturaProveedor();
        $where = [
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere(
                'numproveedor',
                $numproveedor
            ),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere(
                'codproveedor',
                $codproveedor
            ),
        ];

        if (!empty($fecha)) {
            $where[] = new \FacturaScripts\Core\Base\DataBase\DataBaseWhere(
                'fecha',
                $fecha
            );
        }

        $matches = $factura->all($where, [], 0, 1);
        if (empty($matches)) {
            return null;
        }

        $existing = $matches[0];
        return [
            'type' => 'existing_invoice',
            'invoice_id' => $existing->idfactura,
            'invoice_code' => $existing->codigo,
            'message' => Tools::lang()->trans(
                'aiscan-duplicate-invoice-exists',
                ['%code%' => $existing->codigo]
            ),
        ];
    }

    private function resolveInvoiceCode(?int $invoiceId): ?string
    {
        if (!$invoiceId) {
            return null;
        }
        $invoice = new \FacturaScripts\Dinamic\Model\FacturaProveedor();
        if ($invoice->loadFromCode($invoiceId)) {
            return $invoice->codigo;
        }
        return null;
    }
}
