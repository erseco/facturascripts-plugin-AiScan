<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\AiScan\Controller;

use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AiScan\Lib\AiScanSettings;
use FacturaScripts\Plugins\AiScan\Lib\ExtractionService;
use FacturaScripts\Plugins\AiScan\Lib\SupplierMatcher;

class AiScanInvoice extends Controller
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'purchases';
        $data['title'] = 'aiscan-invoice';
        $data['icon'] = 'fa-solid fa-file-invoice';
        $data['showonmenu'] = false;
        return $data;
    }

    public function run(): void
    {
        if (!$this->permissions->allowUpdate) {
            $this->setTemplate(false);
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied']);
            return;
        }

        $action = $this->request->get('action', '');

        $this->setTemplate(false);
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
                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Unknown action']);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            $message = AiScanSettings::isDebugMode() ? $e->getMessage() : 'Internal server error';
            echo json_encode(['error' => $message]);
            Tools::log()->error('AiScan error: ' . $e->getMessage());
        }
    }

    private function handleUpload(): void
    {
        if (!isset($_FILES['invoice_file'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded']);
            return;
        }

        $file = $_FILES['invoice_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'Upload error: ' . $file['error']]);
            return;
        }

        $maxSizeBytes = AiScanSettings::getMaxUploadSizeMb() * 1024 * 1024;
        if ($file['size'] > $maxSizeBytes) {
            http_response_code(413);
            echo json_encode([
                'error' => 'File too large. Maximum size: ' . AiScanSettings::getMaxUploadSizeMb() . 'MB',
            ]);
            return;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            http_response_code(415);
            echo json_encode(['error' => 'Unsupported file type: ' . $mimeType]);
            return;
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            http_response_code(415);
            echo json_encode(['error' => 'Unsupported file extension: ' . $extension]);
            return;
        }

        $tmpDir = FS_FOLDER . '/MyFiles/aiscan_tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $tmpFilename = uniqid('aiscan_') . '.' . $extension;
        $tmpPath = $tmpDir . '/' . $tmpFilename;

        if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to store uploaded file']);
            return;
        }

        echo json_encode([
            'success' => true,
            'tmp_file' => $tmpFilename,
            'original_name' => $file['name'],
            'mime_type' => $mimeType,
            'size' => $file['size'],
            'auto_scan' => AiScanSettings::isAutoScanEnabled(),
        ]);
    }

    private function handleAnalyze(): void
    {
        $tmpFile = $this->request->get('tmp_file', '');
        $mimeType = $this->request->get('mime_type', '');

        if (empty($tmpFile)) {
            http_response_code(400);
            echo json_encode(['error' => 'No file specified']);
            return;
        }

        $tmpFile = basename($tmpFile);
        $tmpPath = FS_FOLDER . '/MyFiles/aiscan_tmp/' . $tmpFile;

        if (!file_exists($tmpPath)) {
            http_response_code(404);
            echo json_encode(['error' => 'Temporary file not found']);
            return;
        }

        if (!AiScanSettings::isEnabled()) {
            http_response_code(503);
            echo json_encode(['error' => 'AiScan plugin is disabled']);
            return;
        }

        $service = new ExtractionService();
        $extracted = $service->extractFromFile($tmpPath, $mimeType);

        if (!empty($extracted['supplier'])) {
            $matcher = new SupplierMatcher();
            $matchResult = $matcher->findMatch($extracted['supplier']);
            $extracted['supplier']['match_status'] = $matchResult['match_status'];
            if ($matchResult['supplier']) {
                $extracted['supplier']['matched_supplier_id'] = $matchResult['supplier']->codproveedor;
                $extracted['supplier']['matched_name'] = $matchResult['supplier']->nombre;
            }
            if (!empty($matchResult['candidates'])) {
                $extracted['supplier']['candidates'] = array_map(function ($s) {
                    return ['id' => $s->codproveedor, 'name' => $s->nombre, 'tax_id' => $s->cifnif];
                }, $matchResult['candidates']);
            }
        }

        echo json_encode(['success' => true, 'data' => $extracted]);
    }

    private function handleMatchSupplier(): void
    {
        $name = $this->request->get('name', '');
        $taxId = $this->request->get('tax_id', '');

        $matcher = new SupplierMatcher();
        $matchResult = $matcher->findMatch(['name' => $name, 'tax_id' => $taxId]);

        $response = ['match_status' => $matchResult['match_status']];
        if ($matchResult['supplier']) {
            $response['supplier'] = [
                'id' => $matchResult['supplier']->codproveedor,
                'name' => $matchResult['supplier']->nombre,
                'tax_id' => $matchResult['supplier']->cifnif,
            ];
        }
        if (!empty($matchResult['candidates'])) {
            $response['candidates'] = array_map(function ($s) {
                return ['id' => $s->codproveedor, 'name' => $s->nombre, 'tax_id' => $s->cifnif];
            }, $matchResult['candidates']);
        }

        echo json_encode($response);
    }
}
