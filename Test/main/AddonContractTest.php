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

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\AiScan\Lib\AiScanSettings;
use FacturaScripts\Plugins\AiScan\Lib\ExtractionService;
use FacturaScripts\Plugins\AiScan\Lib\HistoricalContextService;
use FacturaScripts\Plugins\AiScan\Lib\ImageToPdfConverter;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceEnricher;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use FacturaScripts\Plugins\AiScan\Lib\ProductMatcher;
use FacturaScripts\Plugins\AiScan\Lib\SchemaValidator;
use FacturaScripts\Plugins\AiScan\Lib\SupplierMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Uses AiScan the way other plugins do (for example, AIScanConnect): if a
 * change breaks this test, it breaks them too. The stable API is listed in
 * the README, "Integración con otros plugins".
 */
final class AddonContractTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];
    private ?Proveedor $supplier = null;

    protected function tearDown(): void
    {
        if ($this->supplier !== null) {
            $where = [Where::eq('codproveedor', $this->supplier->codproveedor)];
            foreach ((new FacturaProveedor())->all($where, [], 0, 0) as $invoice) {
                foreach ($invoice->getReceipts() as $receipt) {
                    $receipt->delete();
                }
                foreach ($invoice->getLines() as $line) {
                    $line->delete();
                }
                $invoice->delete();
            }
            $address = $this->supplier->getDefaultAddress();
            if ($address->exists()) {
                $address->delete();
            }
            $this->supplier->delete();
        }

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        Tools::settingsClear();
    }

    public function testSettingsAndModelChoices(): void
    {
        $provider = AiScanSettings::getDefaultProvider();
        $this->assertIsBool(AiScanSettings::isEnabled());
        $this->assertIsString(AiScanSettings::getDefaultModel($provider));
        $this->assertGreaterThan(0, AiScanSettings::getMaxUploadSizeMb());
        $this->assertContains('pdf', AiScanSettings::getAllowedExtensions());

        Tools::settingsSet('AiScan', 'debug_mode', true);
        $choices = (new ExtractionService())->getAvailableModels();
        $this->assertContains(['provider' => 'mock', 'model' => ''], $choices);
    }

    public function testExtractionAcceptsPositionalArguments(): void
    {
        Tools::settingsSet('AiScan', 'debug_mode', true);

        $result = (new ExtractionService())->extractFromFile(
            $this->createPng(),
            'image/png',
            'mock',
            'lines',
            '',
            'F-2024-004',
            null
        );

        $this->assertArrayNotHasKey('_multi_invoice', $result);
        $this->assertSame('mock', $result['_provider']);
        $this->assertNotEmpty($result['supplier']['name']);
        $this->assertNotEmpty($result['invoice']['number']);
        $this->assertNotEmpty($result['lines']);
    }

    public function testSupplierMatchHistoryAndTaxIdHelpers(): void
    {
        $supplier = $this->createSupplier();

        $match = (new SupplierMatcher())->findMatch(['name' => $supplier->nombre, 'tax_id' => $supplier->cifnif]);
        $this->assertSame('matched', $match['match_status']);
        $this->assertSame($supplier->codproveedor, $match['supplier']->codproveedor);
        $this->assertSame('tax_id', $match['match_source']);
        $this->assertSame([], $match['candidates']);

        $history = new HistoricalContextService();
        $this->assertIsString($history->formatForPrompt($history->buildContext($supplier->codproveedor)));
        $this->assertNull($history->getSuggestedProduct($supplier->codproveedor));

        $completed = (new SchemaValidator())->completeMatchedSupplierTaxId(
            ['supplier' => ['matched_supplier_id' => $supplier->codproveedor, 'tax_id' => '']],
            $supplier->cifnif
        );
        $this->assertSame($supplier->cifnif, $completed['supplier']['tax_id']);
    }

    public function testEnrichAndImportWithACustomProductMatcher(): void
    {
        $supplier = $this->createSupplier();
        $matcher = new class () extends ProductMatcher {
            public int $calls = 0;

            public function findReference(array $lineData, ?string $suggestedReference = null): ?string
            {
                ++$this->calls;
                return parent::findReference($lineData, $suggestedReference);
            }
        };

        $enriched = (new InvoiceEnricher(productMatcher: $matcher))->enrich([
            'supplier' => ['name' => $supplier->nombre, 'tax_id' => $supplier->cifnif],
            'invoice' => ['number' => 'CTR-' . bin2hex(random_bytes(4)), 'issue_date' => '2026-11-03',
                'currency' => 'EUR'],
            'lines' => [['descripcion' => 'Contract ' . bin2hex(random_bytes(6)), 'cantidad' => 1,
                'pvpunitario' => 10.0, 'iva' => 0]],
        ]);
        $this->assertSame($supplier->codproveedor, $enriched['supplier']['matched_supplier_id']);
        $this->assertNull((new InvoiceEnricher())->findDuplicateInvoice($enriched));

        $result = (new InvoiceMapper(productMatcher: $matcher))->mapToInvoice($enriched, null, 'lines', false);

        $this->assertTrue($result['success'], implode('; ', $result['errors']));
        $this->assertGreaterThan(0, (int) $result['invoice_id']);
        $this->assertIsArray($result['warnings']);
        $this->assertSame(1, $matcher->calls);
        $duplicate = (new InvoiceEnricher())->findDuplicateInvoice($enriched);
        $this->assertEquals($result['invoice_id'], $duplicate['invoice_id']);
    }

    public function testImageToPdfConversion(): void
    {
        $converter = new ImageToPdfConverter();
        $this->assertTrue($converter->isConvertibleImage('image/png'));

        $converted = $converter->convert($this->createPng(), 'image/png', 'scan.png');
        $this->tempFiles[] = $converted['path'];

        $this->assertSame('application/pdf', $converted['mime']);
        $this->assertFileExists($converted['path']);
    }

    private function createPng(): string
    {
        $path = sys_get_temp_dir() . '/aiscan-contract-' . bin2hex(random_bytes(4)) . '.png';
        $image = imagecreatetruecolor(40, 60);
        imagefilledrectangle($image, 0, 0, 40, 60, imagecolorallocate($image, 255, 255, 255));
        imagepng($image, $path);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function createSupplier(): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = 'AiScan Contract ' . mt_rand(10000, 99999);
        $supplier->razonsocial = $supplier->nombre;
        $supplier->cifnif = 'B' . mt_rand(10000000, 99999999);
        $supplier->personafisica = false;
        foreach ((new FormaPago())->all([], [], 0, 1) as $method) {
            $supplier->codpago = $method->codpago;
        }
        foreach ((new Serie())->all([], [], 0, 1) as $serie) {
            $supplier->codserie = $serie->codserie;
        }
        $this->assertTrue($supplier->save());
        $this->supplier = $supplier;

        return $supplier;
    }
}
