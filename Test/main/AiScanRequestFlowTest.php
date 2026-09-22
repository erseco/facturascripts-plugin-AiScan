<?php

/**
 * AiScan tests. Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>.
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

namespace FacturaScripts\Plugins\AiScan\Controller;

use FacturaScripts\Test\Plugins\AiScanRequestFlowTest;

function file_get_contents(string $path)
{
    return $path === 'php://input' ? AiScanRequestFlowTest::$body : \file_get_contents($path);
}

function header(string $header): void
{
    AiScanRequestFlowTest::$headers[] = $header;
}

function http_response_code(int $status): int
{
    return AiScanRequestFlowTest::$status = $status;
}

function move_uploaded_file(string $source, string $destination): bool
{
    if (!in_array($source, AiScanRequestFlowTest::$uploads, true)) {
        return false;
    }
    AiScanRequestFlowTest::$uploads[] = $destination;
    return rename($source, $destination);
}

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Request;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AttachedFile;
use FacturaScripts\Dinamic\Model\AttachedFileRelation;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\AiScan\Controller\AiScanConfig;
use FacturaScripts\Plugins\AiScan\Controller\AiScanInvoice;
use FacturaScripts\Plugins\AiScan\Controller\EditAiScanImportBatch;
use FacturaScripts\Plugins\AiScan\Controller\ListAiScanHistory;
use FacturaScripts\Plugins\AiScan\Lib\AttachmentService;
use FacturaScripts\Plugins\AiScan\Lib\ExtractionService;
use FacturaScripts\Plugins\AiScan\Lib\HistoricalContextService;
use FacturaScripts\Plugins\AiScan\Model\AiScanImportBatch;
use FacturaScripts\Plugins\AiScan\Model\AiScanSupplierAlias;
use FacturaScripts\Plugins\AiScan\Model\AiScanSupplierProduct;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AiScanRequestFlowTest extends TestCase
{
    public static string $body = '';
    public static int $status = 200;
    public static array $headers = [];
    public static array $uploads = [];
    private array $suppliers = [];
    private array $invoices = [];
    private array $products = [];
    private array $batches = [];
    private array $savedFiles = [];
    private array $originalFiles = [];
    private $originalUser;

    protected function setUp(): void
    {
        $this->originalFiles = $_FILES;
        $this->originalUser = Session::get('user');
        $_FILES = [];
        Tools::settingsSet('AiScan', 'debug_mode', true);
        Tools::settingsSet('AiScan', 'enabled', true);
        Tools::settingsSet('AiScan', 'default_provider', 'mock');
    }

    protected function tearDown(): void
    {
        foreach ($this->batches as $id) {
            $batch = new AiScanImportBatch();
            if ($batch->load($id)) {
                foreach ($batch->getDocuments() as $document) {
                    foreach ($document->getLines() as $line) {
                        $line->delete();
                    }
                    $document->delete();
                }
                $batch->delete();
            }
        }
        foreach (array_unique($this->invoices) as $id) {
            $invoice = new FacturaProveedor();
            if ($invoice->load($id)) {
                foreach ($invoice->getReceipts() as $receipt) {
                    $receipt->delete();
                }
                foreach ($invoice->getLines() as $line) {
                    $line->delete();
                }
                $invoice->delete();
            }
        }
        foreach ($this->suppliers as $id) {
            AiScanSupplierProduct::clearForSupplier($id);
            foreach (AiScanSupplierAlias::all([Where::eq('codproveedor', $id)]) as $alias) {
                $alias->delete();
            }
            $supplier = new Proveedor();
            if ($supplier->load($id)) {
                $address = $supplier->getDefaultAddress();
                if ($address->exists()) {
                    $address->delete();
                }
                $supplier->delete();
            }
        }
        foreach ($this->products as $product) {
            $product->delete();
        }
        foreach (array_unique(array_merge(self::$uploads, $this->savedFiles)) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $_FILES = $this->originalFiles;
        Session::set('user', $this->originalUser);
        Tools::settingsClear();
        Tools::log()->clear();
        self::$body = '';
        self::$headers = [];
        self::$uploads = [];
        ProviderTransportTest::$response = null;
    }

    public function testEndpointsRejectMissingAndMalformedInput(): void
    {
        foreach (['upload', 'analyze', 'get-text', 'does-not-exist'] as $action) {
            $result = $this->call($action);
            self::assertArrayHasKey('error', $result);
            self::assertSame(400, self::$status);
        }
        foreach (
            ['create-supplier', 'set-supplier-default-product', 'remember-supplier-alias',
                'apply', 'import-batch'] as $action
        ) {
            self::assertArrayHasKey('error', $this->call($action, [], 'invalid JSON'));
            self::assertSame(400, self::$status);
        }
        foreach (['analyze', 'get-text'] as $action) {
            self::assertArrayHasKey('error', $this->call($action, ['tmp_file' => 'invalid file.pdf']));
            self::assertSame(400, self::$status);
            self::assertArrayHasKey('error', $this->call($action, ['tmp_file' => 'absent-coverage.pdf']));
            self::assertSame(404, self::$status);
        }
        self::assertSame(['results' => []], $this->call('search-suppliers', ['query' => 'x']));
        self::assertSame(['results' => []], $this->call('search-products', ['query' => 'x']));
        self::assertSame(['context' => []], $this->call('get-historical-context'));
        self::assertSame(['found' => false], $this->call('get-supplier-default-product'));
        self::assertSame(['found' => false], $this->call('suggest-supplier-products'));
        self::assertArrayHasKey('error', $this->call('create-supplier', [], ['name' => '']));
        self::assertArrayHasKey('error', $this->call('set-supplier-default-product', [], []));
        self::assertArrayHasKey('error', $this->call('remember-supplier-alias', [], []));
        self::assertArrayHasKey('error', $this->call('remember-supplier-alias', [], ['codproveedor' => 'missing']));
        self::assertSame(404, self::$status);
        Tools::settingsSet('AiScan', 'debug_mode', false);
        self::assertArrayHasKey('error', $this->call('list-mock-fixtures'));
        self::assertSame(403, self::$status);
    }

    public function testPostParametersTakePrecedenceOverQueryParameters(): void
    {
        $result = $this->call('upload', [], [], ['action' => 'get-supplier-default-product']);
        self::assertSame(['found' => false], $result);
        self::assertSame(200, self::$status);
    }

    public function testSupplierCreationSearchMatchingAndAliasLearning(): void
    {
        $name = 'Coverage Supplier ' . bin2hex(random_bytes(4));
        $tax = 'Z' . mt_rand(10000000, 99999999);
        $created = $this->call('create-supplier', [], [
            'name' => $name, 'tax_id' => $tax, 'email' => 'supplier@example.invalid', 'is_creditor' => true,
        ]);
        self::assertTrue($created['success'], json_encode($created));
        $id = $created['supplier']['id'];
        $this->suppliers[] = $id;
        self::assertSame($name, $created['supplier']['name']);
        foreach ([$name, $tax] as $query) {
            self::assertSame($id, $this->call('search-suppliers', ['query' => $query])['results'][0]['id']);
        }
        $matched = $this->call('match-supplier', ['name' => $name, 'tax_id' => $tax]);
        self::assertSame($id, $matched['supplier']['id']);
        self::assertSame(['found' => false], $this->call('get-supplier-default-product', ['codproveedor' => $id]));
        self::assertFalse($this->call('remember-supplier-alias', [], ['codproveedor' => $id])['success']);
        $alias = $this->call('remember-supplier-alias', [], ['codproveedor' => $id, 'name' => 'Trading ' . $name]);
        self::assertTrue($alias['success']);
        self::assertSame($id, $this->call('match-supplier', ['name' => 'Trading ' . $name])['supplier']['id']);
        $cleared = $this->call('set-supplier-default-product', [], ['codproveedor' => $id, 'clear' => true]);
        self::assertTrue($cleared['cleared']);
        self::assertArrayHasKey('context', $this->call('get-historical-context', ['codproveedor' => $id]));
        self::assertSame(['found' => false], $this->call('suggest-supplier-products', ['codproveedor' => $id]));
        self::assertArrayHasKey('results', $this->call('search-products', ['query' => 'coverage']));
    }

    public function testUploadAnalysisManualFallbackAndTextExtraction(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'aiscan-coverage-');
        file_put_contents($file, '%PDF-1.4 coverage fixture');
        self::$uploads[] = $file;
        $_FILES['invoice_file'] = [
            'error' => UPLOAD_ERR_OK, 'name' => 'invoice.pdf', 'size' => filesize($file),
            'tmp_name' => $file, 'type' => 'application/pdf',
        ];
        $upload = $this->call('upload');
        self::assertTrue($upload['success']);
        self::assertSame('application/pdf', $upload['mime_type']);
        self::assertContains('mock', $upload['available_providers']);
        $params = ['tmp_file' => $upload['tmp_file'], 'mime_type' => 'application/pdf',
            'provider' => 'mock', 'mock_fixture' => 'F-2024-011'];
        $result = $this->call('analyze', $params);
        self::assertTrue($result['success'], json_encode($result));
        self::assertSame('mock', $result['data']['_provider']);
        self::assertNotEmpty($result['data']['invoice']['number']);
        $result = $this->call('analyze', array_merge($params, ['provider' => 'nonexistent']));
        self::assertTrue($result['scan_failed']);
        self::assertTrue($this->call('get-text', $params)['success']);
        Tools::settingsSet('AiScan', 'enabled', false);
        self::assertArrayHasKey('error', $this->call('analyze', $params));
        self::assertSame(503, self::$status);
        $fixtures = $this->call('list-mock-fixtures', ['current' => 'F-2024-011']);
        self::assertNotEmpty($fixtures['fixtures']);
        self::assertNotEmpty($fixtures['next']);
        $_FILES['invoice_file']['error'] = UPLOAD_ERR_INI_SIZE;
        self::assertArrayHasKey('errors', $this->call('upload'));
        self::assertSame(422, self::$status);
    }

    public function testBatchPersistsDiscardedUnreadyAndFailedDocumentsSeparately(): void
    {
        $line = ['description' => 'Reviewed service', 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 0];
        $batch = $this->call('import-batch', [], ['documents' => [
            ['status' => 'discarded', 'original_name' => 'discarded.pdf', 'extracted_data' => ['lines' => [$line]]],
            ['status' => 'needs_review', 'original_name' => 'review.pdf'],
            ['status' => 'ready', 'original_name' => 'empty.pdf'],
            ['status' => 'ready', 'original_name' => 'invalid.pdf', 'extracted_data' => ['invoice' => ['total' => 10]]],
        ]]);
        self::assertTrue($batch['success']);
        $this->batches[] = $batch['batch_id'];
        self::assertSame(['skipped', 'error', 'error', 'error'], array_column($batch['results'], 'status'));
        $history = new AiScanImportBatch();
        self::assertTrue($history->load($batch['batch_id']));
        self::assertSame(1, $history->discardedcount);
        self::assertSame(3, $history->failedcount);
        self::assertCount(4, $history->getDocuments());
        self::assertSame('Reviewed service', $history->getDocuments()[0]->getLines()[0]->descripcion);
        self::assertArrayHasKey('error', $this->call('apply', [], ['invoice' => ['total' => 10]]));
        self::assertSame(422, self::$status);
    }

    public function testSuccessfulImportProtectsPostedInvoiceAndPersistsBatchHistory(): void
    {
        $supplier = $this->call('create-supplier', [], ['name' => 'Import ' . bin2hex(random_bytes(5))]);
        self::assertTrue($supplier['success']);
        $id = $supplier['supplier']['id'];
        $this->suppliers[] = $id;
        $data = [
            'supplier' => ['matched_supplier_id' => $id, 'match_status' => 'matched'],
            'invoice' => ['number' => 'FLOW-' . bin2hex(random_bytes(5)), 'issue_date' => '2026-06-01',
                'currency' => 'EUR', 'summary' => 'Coverage service'],
            'lines' => [['description' => 'Coverage service', 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 0]],
        ];
        $result = $this->call('apply', [], $data);
        self::assertTrue($result['success'] ?? false, json_encode($result));
        $this->invoices[] = $result['invoice_id'];
        $invoice = new FacturaProveedor();
        self::assertTrue($invoice->load($result['invoice_id']));
        self::assertEquals(100, $invoice->total);
        $name = 'coverage-' . bin2hex(random_bytes(5)) . '.pdf';
        $tmp = FS_FOLDER . '/MyFiles/aiscan_tmp/' . $name;
        file_put_contents($tmp, '%PDF-1.4 source');
        $this->savedFiles[] = $tmp;
        (new AttachmentService())->attachTemporaryFile($invoice, ['tmp_file' => $name,
            'original_name' => 'Scanned source.jpg']);
        $relations = (new AttachedFileRelation())->all([
            Where::eq('model', 'FacturaProveedor'), Where::eq('modelid', $invoice->idfactura),
        ]);
        $files = [];
        foreach ($relations as $relation) {
            $file = new AttachedFile();
            self::assertTrue($file->load($relation->idfile));
            $files[] = $file;
        }
        try {
            self::assertCount(1, $files);
            self::assertSame('Scanned-source.pdf', $files[0]->filename);
            self::assertFileExists($files[0]->getFullPath());
            self::assertCount(1, $relations);
            self::assertEquals($invoice->idfactura, $relations[0]->modelid);
        } finally {
            foreach ($files as $file) {
                foreach ((new AttachedFileRelation())->all([Where::eq('idfile', $file->idfile)]) as $relation) {
                    $relation->delete();
                }
                $file->delete();
            }
        }
        $product = new Producto();
        $product->referencia = 'CV-' . bin2hex(random_bytes(4));
        $product->descripcion = 'Coverage service';
        self::assertTrue($product->save());
        $this->products[] = $product;
        $pin = $this->call('set-supplier-default-product', [], ['codproveedor' => $id,
            'referencia' => $product->referencia, 'description' => $product->descripcion]);
        self::assertTrue($pin['success']);
        self::assertTrue($this->call('get-supplier-default-product', ['codproveedor' => $id])['found']);
        self::assertSame('pinned', $this->call('suggest-supplier-products', ['codproveedor' => $id])['source']);
        $enrich = new ReflectionMethod(AiScanInvoice::class, 'enrichExtractedData');
        $enrich->setAccessible(true);
        $data['supplier']['name'] = $supplier['supplier']['name'];
        $enriched = $enrich->invoke(new AiScanInvoice('AiScanInvoice'), $data);
        self::assertSame($invoice->idfactura, $enriched['_duplicate']['invoice_id']);
        self::assertSame($product->referencia, $enriched['lines'][0]['referencia']);
        $data['lines'][0]['unit_price'] = 75;
        $updated = $this->call('apply', ['invoice_id' => (string)$invoice->idfactura], $data);
        self::assertArrayHasKey('error', $updated);
        self::assertSame(422, self::$status);
        self::assertTrue($invoice->load($invoice->idfactura));
        self::assertEquals(100, $invoice->total);
        self::assertCount(1, $this->call('get-historical-context', ['codproveedor' => $id])['context']);
        self::assertTrue($this->call('suggest-supplier-products', ['codproveedor' => $id])['found']);
        $data['invoice']['number'] .= '-BATCH';
        $batch = $this->call('import-batch', [], ['documents' => [[
            'status' => 'ready', 'original_name' => 'coverage.pdf', 'extracted_data' => $data,
            'import_mode' => 'lines', 'update_stock_purchase_data' => false,
        ]]]);
        $this->batches[] = $batch['batch_id'];
        self::assertSame('imported', $batch['results'][0]['status'], json_encode($batch));
        $this->invoices[] = $batch['results'][0]['invoice_id'];
        $history = new AiScanImportBatch();
        self::assertTrue($history->load($batch['batch_id']));
        self::assertSame(1, $history->importedcount);
        self::assertSame('imported', $history->getDocuments()[0]->status);
        $this->checkHistoryViews($batch['batch_id']);
        $context = new HistoricalContextService();
        self::assertStringContainsString('Coverage service', $context->formatForPrompt($context->buildContext($id)));
        foreach ([[], [['base' => 50, 'rate' => 0], ['base' => 50, 'rate' => 21]]] as $taxes) {
            $data['invoice']['number'] .= '-TOTAL';
            $data['invoice']['subtotal'] = 100;
            $data['invoice']['total'] = empty($taxes) ? 100 : 110.5;
            $data['taxes'] = $taxes;
            $total = $this->call('apply', ['import_mode' => 'total'], $data);
            self::assertTrue($total['success'] ?? false, json_encode($total));
            $this->invoices[] = $total['invoice_id'];
            self::assertTrue($invoice->load($total['invoice_id']));
            self::assertEquals($data['invoice']['total'], $invoice->total);
            self::assertCount(empty($taxes) ? 1 : 2, $invoice->getLines());
        }
    }

    public function testSettingsAndHistoryViewsUseRealCoreViews(): void
    {
        $config = new AiScanConfig('AiScanConfig');
        self::assertSame('admin', $config->getPageData()['menu']);
        $method = new ReflectionMethod($config, 'createViews');
        $method->setAccessible(true);
        $method->invoke($config);
        self::assertArrayHasKey('AiScanConfig', $config->views);
        $load = new ReflectionMethod($config, 'loadData');
        $load->setAccessible(true);
        $load->invoke($config, 'AiScanConfig', $config->views['AiScanConfig']);
        self::assertSame('AiScan', $config->views['AiScanConfig']->model->name);
        $action = new ReflectionMethod($config, 'execPreviousAction');
        $action->setAccessible(true);
        ob_start();
        try {
            self::assertFalse($action->invoke($config, 'get-base-prompt'));
            $result = json_decode(ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
        self::assertSame(ExtractionService::getDefaultSystemPrompt(), $result['prompt']);
        self::assertArrayHasKey('close', $result['i18n']);
        $list = new ListAiScanHistory('ListAiScanHistory');
        self::assertFalse($list->getPageData()['showonmenu']);
        $method = new ReflectionMethod($list, 'createViews');
        $method->setAccessible(true);
        $method->invoke($list);
        self::assertArrayHasKey('ListAiScanImportBatch', $list->views);
        $this->checkHistoryViews(-1);
    }

    public function testLandingPageProvidesConfiguredChoicesAndAssets(): void
    {
        $controller = new class ('AiScanInvoice') extends AiScanInvoice {
            public array $page = [];

            public function request(): Request
            {
                return new Request();
            }

            protected function auth(): bool
            {
                $user = new User();
                $user->nick = 'admin';
                $user->admin = true;
                Session::set('user', $user);
                return true;
            }

            protected function view(string $view, array $data = []): void
            {
                $this->page = ['template' => $view, 'data' => $data];
            }
        };
        $controller->run();
        self::assertSame('AiScanInvoice.html.twig', $controller->page['template']);
        self::assertContains('mock', $controller->page['data']['availableProviders']);
        self::assertNotEmpty($controller->page['data']['taxExceptions']);
        self::assertNotEmpty($controller->page['data']['mockFixtures']);
        Tools::settingsSet('AiScan', 'debug_mode', false);
        $controller->run();
        self::assertSame([], $controller->page['data']['mockFixtures']);
    }

    public function testMultipleUploadsRejectInvalidFilesAndConvertImages(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'aiscan-upload-');
        file_put_contents($source, 'plain file');
        $this->savedFiles[] = $source;
        $base = ['error' => UPLOAD_ERR_OK, 'name' => 'invoice.pdf', 'size' => 10, 'tmp_name' => $source];
        foreach ([['size' => PHP_INT_MAX], ['name' => 'invoice.exe'], []] as $override) {
            $_FILES['invoice_file'] = array_merge($base, $override);
            self::assertArrayHasKey('errors', $this->call('upload'));
            self::assertSame(422, self::$status);
        }
        unset($_FILES['invoice_file']);
        $pdf = tempnam(sys_get_temp_dir(), 'aiscan-pdf-');
        file_put_contents($pdf, '%PDF-1.4 fixture');
        self::$uploads[] = $pdf;
        $jpeg = tempnam(sys_get_temp_dir(), 'aiscan-jpeg-');
        $fixture = FS_FOLDER . '/Plugins/AiScan/Test/fixtures/F-2025-007.jpg';
        self::assertFileExists($fixture);
        copy($fixture, $jpeg);
        self::$uploads[] = $jpeg;
        $_FILES['invoice_files'] = [
            'name' => ['first.pdf', 'photo.jpg', 'rejected.exe'],
            'tmp_name' => [$pdf, $jpeg, $source], 'size' => [filesize($pdf), filesize($jpeg), 10],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_OK],
        ];
        $result = $this->call('upload', ['convert_to_pdf' => '1']);
        self::assertTrue($result['success'], json_encode($result));
        self::assertCount(2, $result['files']);
        self::assertCount(1, $result['errors']);
        self::assertArrayNotHasKey('tmp_file', $result);
        foreach ($result['files'] as $file) {
            $path = FS_FOLDER . '/MyFiles/aiscan_tmp/' . $file['tmp_file'];
            $this->savedFiles[] = $path;
            self::assertSame('application/pdf', $file['mime_type']);
            self::assertStringStartsWith('%PDF-', file_get_contents($path));
        }
        Tools::settingsSet('AiScan', 'openai_api_key', 'not-a-real-key');
        ProviderTransportTest::$response = json_encode(['choices' => [['message' => [
            'content' => json_encode(['invoices' => [
                ['invoice' => ['number' => 'A', 'issue_date' => '2026-06-01', 'total' => 1]],
                ['invoice' => ['number' => 'B', 'issue_date' => '2026-06-01', 'total' => 2]],
            ]]),
        ]]]]);
        $analysis = $this->call('analyze', ['tmp_file' => $result['files'][0]['tmp_file'],
            'mime_type' => 'application/pdf', 'provider' => 'openai',
            'use_history' => '1', 'supplier_id' => 'missing']);
        self::assertTrue($analysis['_multi_invoice'] ?? false, json_encode($analysis));
        self::assertCount(2, $analysis['invoices']);
    }

    private function checkHistoryViews(int $id): void
    {
        $page = new EditAiScanImportBatch('EditAiScanImportBatch');
        $page->request = new Request(['query' => ['code' => (string)$id]]);
        self::assertFalse($page->getPageData()['showonmenu']);
        $create = new ReflectionMethod($page, 'createViews');
        $create->setAccessible(true);
        $create->invoke($page);
        self::assertCount(3, $page->views);
        $load = new ReflectionMethod($page, 'loadData');
        $load->setAccessible(true);
        foreach ($page->views as $name => $view) {
            $load->invoke($page, $name, $view);
        }
        self::assertSame($id > 0, $page->views['EditAiScanImportBatch']->model->exists());
    }

    private function call(string $action, array $query = [], $body = [], array $form = []): array
    {
        self::$body = is_string($body) ? $body : json_encode($body);
        self::$headers = [];
        self::$status = 200;
        $controller = new class ('AiScanInvoice') extends AiScanInvoice {
            public Request $testRequest;

            public function request(): Request
            {
                return $this->testRequest;
            }

            protected function auth(): bool
            {
                $user = new User();
                $user->admin = true;
                $user->nick = 'admin';
                Session::set('user', $user);
                return true;
            }
        };
        $controller->testRequest = new Request(['query' => array_merge($query, ['action' => $action]), 'request' => $form]);
        ob_start();
        try {
            $controller->run();
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertContains('Content-Type: application/json', self::$headers);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
