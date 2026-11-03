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

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AttachedFile;
use FacturaScripts\Dinamic\Model\AttachedFileRelation;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use FacturaScripts\Plugins\AiScan\Model\AiScanSupplierProduct;
use PHPUnit\Framework\TestCase;

/**
 * Options other plugins pass to InvoiceMapper when they review the
 * extraction themselves (for example, AIScanConnect).
 */
final class InvoiceMapperAddonOptionsTest extends TestCase
{
    /** @var AttachedFile[] */
    private array $files = [];
    private ?Producto $product = null;
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
            AiScanSupplierProduct::clearForSupplier($this->supplier->codproveedor);
            $address = $this->supplier->getDefaultAddress();
            if ($address->exists()) {
                $address->delete();
            }
            $this->supplier->delete();
        }

        foreach ($this->files as $file) {
            foreach ((new AttachedFileRelation())->all([Where::eq('idfile', $file->idfile)]) as $relation) {
                $relation->delete();
            }
            $file->delete();
        }

        $this->product?->delete();
    }

    /**
     * A reviewer may leave a line without product on purpose: the supplier's
     * usual product must not be linked again during the import.
     */
    public function testUnlinkedLineSkipsSupplierUsualProduct(): void
    {
        $supplier = $this->createSupplier();
        $this->product = new Producto();
        $this->product->referencia = 'ADD-' . bin2hex(random_bytes(4));
        $this->product->descripcion = 'Addon usual ' . bin2hex(random_bytes(6));
        $this->assertTrue($this->product->save());
        $this->assertTrue(AiScanSupplierProduct::setForSupplier(
            $supplier->codproveedor,
            $this->product->referencia,
            $this->product->descripcion
        ));

        $linked = 'Linked by history ' . bin2hex(random_bytes(6));
        $unlinked = 'Left unlinked ' . bin2hex(random_bytes(6));
        $data = $this->buildData($supplier);
        $data['lines'][] = ['descripcion' => $linked, 'cantidad' => 1, 'pvpunitario' => 10.0, 'iva' => 0];
        $data['lines'][] = ['descripcion' => $unlinked, 'cantidad' => 1, 'pvpunitario' => 20.0, 'iva' => 0,
            'unlinked' => true];
        $result = (new InvoiceMapper())->mapToInvoice($data, null, 'lines', false);

        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));
        $invoice = new FacturaProveedor();
        $this->assertTrue($invoice->load($result['invoice_id']));
        $lines = [];
        foreach ($invoice->getLines() as $line) {
            $lines[$line->descripcion] = $line;
        }
        $this->assertCount(2, $lines);
        $this->assertSame($this->product->referencia, $lines[$linked]->referencia);
        $this->assertEmpty($lines[$unlinked]->idproducto);
        $this->assertEmpty($lines[$unlinked]->referencia);
    }

    /**
     * A plugin that already keeps the source document as an AttachedFile can
     * link it to the new invoice instead of handing AiScan a second copy.
     */
    public function testExistingAttachedFileIsLinkedWithoutCopy(): void
    {
        $supplier = $this->createSupplier();
        $staged = 'aiscan_addon_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents(FS_FOLDER . '/MyFiles/' . $staged, '%PDF-1.4 addon source');
        $file = new AttachedFile();
        $file->path = $staged;
        $this->assertTrue($file->save());
        $this->files[] = $file;
        $filesBefore = (new AttachedFile())->count();

        $data = $this->buildData($supplier);
        $data['lines'][] = ['descripcion' => 'Servicio', 'cantidad' => 2, 'pvpunitario' => 15.0, 'iva' => 0];
        $data['_idfile'] = $file->idfile;
        $result = (new InvoiceMapper())->mapToInvoice($data, null, 'lines', false);

        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));
        $relations = (new AttachedFileRelation())->all([
            Where::eq('model', 'FacturaProveedor'),
            Where::eq('modelid', $result['invoice_id']),
        ]);
        $this->assertCount(1, $relations);
        $this->assertSame($file->idfile, $relations[0]->idfile);
        $this->assertSame($filesBefore, (new AttachedFile())->count());
    }

    private function buildData(Proveedor $supplier): array
    {
        return [
            'invoice' => [
                'number' => 'ADD-' . mt_rand(10000, 99999),
                'issue_date' => '2026-11-03',
                'currency' => 'EUR',
            ],
            'supplier' => ['matched_supplier_id' => $supplier->codproveedor, 'match_status' => 'matched'],
            'lines' => [],
        ];
    }

    private function createSupplier(): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = 'AiScan Addon ' . mt_rand(10000, 99999);
        $supplier->razonsocial = $supplier->nombre;
        $supplier->cifnif = 'Y' . mt_rand(10000000, 99999999);
        $supplier->personafisica = false;
        foreach ((new FormaPago())->all([], [], 0, 1) as $method) {
            $supplier->codpago = $method->codpago;
        }
        $series = (new Serie())->all([], [], 0, 1);
        if (empty($series)) {
            // A fresh database (CI) has no series until someone creates one.
            $serie = new Serie();
            $serie->codserie = 'A';
            $serie->descripcion = 'Test series';
            $this->assertTrue($serie->save());
            $series[] = $serie;
        }
        $supplier->codserie = $series[0]->codserie;
        $this->assertTrue($supplier->save(), 'Could not create the test supplier');
        $this->supplier = $supplier;

        return $supplier;
    }
}
