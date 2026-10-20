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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\EstadoDocumento;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use FacturaScripts\Plugins\AiScan\Lib\PurchaseLineInventoryUpdater;
use PHPUnit\Framework\TestCase;

/**
 * Issue #133: el estado «Recibida» se buscaba por nombre, y un fallo después de
 * marcar la factura dejaba una factura grabada que se duplicaba al reintentar.
 */
final class InvoiceMapperReceivedStatusTest extends TestCase
{
    /** @var Proveedor[] */
    private array $suppliersToDelete = [];

    /** @var array<int, string> */
    private array $renamedStatuses = [];

    protected function tearDown(): void
    {
        foreach ($this->suppliersToDelete as $supplier) {
            $where = [Where::eq('codproveedor', $supplier->codproveedor)];
            foreach ((new FacturaProveedor())->all($where, [], 0, 0) as $invoice) {
                $this->makeEditable($invoice);
                foreach ($invoice->getReceipts() as $receipt) {
                    $receipt->delete();
                }
                foreach ($invoice->getLines() as $line) {
                    $line->delete();
                }
                $invoice->delete();
            }
            $address = $supplier->getDefaultAddress();
            if ($address->exists()) {
                $address->delete();
            }
            $supplier->delete();
        }

        foreach ($this->renamedStatuses as $id => $name) {
            $this->renameStatus($id, $name);
        }

        MiniLog::clear();
    }

    public function testReceivedStatusDoesNotDependOnItsName(): void
    {
        $received = $this->receivedStatus();
        $this->renamedStatuses[$received->idestado] = $received->nombre;
        // Es un estado bloqueado: el modelo no deja renombrarlo, así que se
        // simula la instalación en inglés directamente en la base de datos.
        $this->renameStatus($received->idestado, 'Received');

        $supplier = $this->createSupplier();
        $result = (new InvoiceMapper())->mapToInvoice($this->buildData($supplier), null, 'lines', false);

        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));
        $invoice = new FacturaProveedor();
        $this->assertTrue($invoice->load($result['invoice_id']));
        $this->assertSame($received->idestado, $invoice->idestado);
    }

    public function testFailureAfterSavingLeavesNoInvoice(): void
    {
        foreach ([new \RuntimeException('stock failed'), new \TypeError('stock crashed')] as $error) {
            $updater = new class ($error) extends PurchaseLineInventoryUpdater {
                public function __construct(private readonly \Throwable $error)
                {
                }

                public function revertAll(FacturaProveedor $invoice): void
                {
                    throw $this->error;
                }
            };
            $supplier = $this->createSupplier();
            $mapper = new InvoiceMapper(inventoryUpdater: $updater);

            $tmpDir = FS_FOLDER . '/MyFiles/aiscan_tmp';
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0700, true);
            }
            $tmpName = 'aiscan_retry_' . bin2hex(random_bytes(4)) . '.pdf';
            file_put_contents($tmpDir . '/' . $tmpName, '%PDF-1.4 retry');
            $data = $this->buildData($supplier);
            $data['_upload'] = ['tmp_file' => $tmpName, 'original_name' => 'retry.pdf'];

            try {
                $result = $mapper->mapToInvoice($data, null, 'lines', false);
                // El reintento necesita el documento escaneado.
                $this->assertFileExists($tmpDir . '/' . $tmpName, get_class($error));
            } finally {
                if (is_file($tmpDir . '/' . $tmpName)) {
                    unlink($tmpDir . '/' . $tmpName);
                }
            }

            $this->assertFalse($result['success'], get_class($error));
            $this->assertContains($error->getMessage(), $result['errors']);
            $this->assertSame(
                0,
                (new FacturaProveedor())->count([Where::eq('codproveedor', $supplier->codproveedor)]),
                'Un reintento no debe encontrarse una factura ya grabada (' . get_class($error) . ')'
            );
        }
    }

    private function renameStatus(int $id, string $name): void
    {
        $db = new DataBase();
        $this->assertTrue($db->exec('UPDATE ' . EstadoDocumento::tableName() . ' SET nombre = '
            . $db->var2str($name) . ' WHERE idestado = ' . $id));
    }

    private function receivedStatus(): EstadoDocumento
    {
        $where = [Where::eq('tipodoc', 'FacturaProveedor'), Where::eq('editable', false)];
        foreach ((new EstadoDocumento())->all($where, ['idestado' => 'ASC'], 0, 0) as $status) {
            if (empty($status->generadoc)) {
                return $status;
            }
        }
        $this->fail('No hay un estado de factura de proveedor no editable');
    }

    private function makeEditable(FacturaProveedor $invoice): void
    {
        if ($invoice->editable) {
            return;
        }
        $where = [Where::eq('tipodoc', 'FacturaProveedor'), Where::eq('editable', true)];
        foreach ((new EstadoDocumento())->all($where, [], 0, 1) as $status) {
            $invoice->idestado = $status->idestado;
            $invoice->save();
        }
    }

    private function buildData(Proveedor $supplier): array
    {
        return [
            'invoice' => [
                'number' => 'RCV-' . mt_rand(10000, 99999),
                'issue_date' => '2026-08-25',
                'currency' => 'EUR',
                'subtotal' => 30.0,
                'tax_amount' => 0.0,
                'total' => 30.0,
            ],
            'supplier' => ['matched_supplier_id' => $supplier->codproveedor, 'match_status' => 'matched'],
            'lines' => [['descripcion' => 'Servicio', 'cantidad' => 2, 'pvpunitario' => 15.0, 'iva' => 0]],
        ];
    }

    private function createSupplier(): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = 'AiScan Recibida ' . mt_rand(10000, 99999);
        $supplier->razonsocial = $supplier->nombre;
        $supplier->cifnif = 'Y' . mt_rand(10000000, 99999999);
        $supplier->personafisica = false;
        foreach ((new FormaPago())->all([], [], 0, 1) as $method) {
            $supplier->codpago = $method->codpago;
        }
        foreach ((new Serie())->all([], [], 0, 1) as $serie) {
            $supplier->codserie = $serie->codserie;
        }
        $this->assertTrue($supplier->save(), 'No se pudo crear el proveedor de prueba');
        $this->suppliersToDelete[] = $supplier;

        return $supplier;
    }
}
