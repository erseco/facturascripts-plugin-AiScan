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

use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\DataSrc\Impuestos;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\EstadoDocumento;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Impuesto;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\AiScan\Lib\InvoiceMapper;
use PHPUnit\Framework\TestCase;

/**
 * Issue #93: valores inventados por la IA en la línea (codimpuesto inexistente,
 * excepción de IVA con el texto legal completo) rompían el guardado y dejaban
 * una factura en boceto a 0 €.
 */
final class InvoiceMapperInvalidTaxTest extends TestCase
{
    /** @var FacturaProveedor[] */
    private array $invoicesToDelete = [];

    /** @var Proveedor[] */
    private array $suppliersToDelete = [];

    /** @var FormaPago[] */
    private array $paymentMethodsToDelete = [];

    /** @var Impuesto[] */
    private array $taxesToDelete = [];

    public static function setUpBeforeClass(): void
    {
        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'FacturaScripts\\Dinamic\\')) {
                $coreClass = str_replace('\\Dinamic\\', '\\Core\\', $class);
                if (!class_exists($class, false) && class_exists($coreClass)) {
                    class_alias($coreClass, $class);
                }
            }
        }, true, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->invoicesToDelete as $invoice) {
            if ($invoice->exists()) {
                foreach ($invoice->getReceipts() as $receipt) {
                    $receipt->delete();
                }
                foreach ($invoice->getLines() as $line) {
                    $line->delete();
                }
                $invoice->delete();
            }
        }

        foreach ($this->suppliersToDelete as $supplier) {
            if ($supplier->exists()) {
                $address = $supplier->getDefaultAddress();
                if ($address->exists()) {
                    $address->delete();
                }
                $supplier->delete();
            }
        }

        foreach ($this->paymentMethodsToDelete as $method) {
            if ($method->exists()) {
                $method->delete();
            }
        }

        foreach ($this->taxesToDelete as $tax) {
            if ($tax->exists()) {
                $tax->delete();
            }
        }

        MiniLog::clear();
    }

    public function testUnknownTaxCodeFallsBackToAnExistingOne(): void
    {
        $supplier = $this->createSupplier();

        $result = (new InvoiceMapper())->mapToInvoice(
            $this->buildData($supplier, ['codimpuesto' => 'IGICEXENTO', 'iva' => 0]),
            null,
            'lines',
            false
        );

        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));

        $invoice = new FacturaProveedor();
        $this->assertTrue($invoice->loadFromCode($result['invoice_id']));
        $this->invoicesToDelete[] = $invoice;

        $lines = $invoice->getLines();
        $this->assertCount(1, $lines);

        $resolved = Impuestos::get((string) $lines[0]->codimpuesto);
        $this->assertNotEmpty($resolved->codimpuesto, 'El impuesto de la línea debe existir en la instalación');
        $this->assertSame(
            Impuestos::default()->operacion,
            $resolved->operacion,
            'El respaldo no puede cambiar de régimen fiscal (IVA / IGIC / IPSI)'
        );
        $this->assertEqualsWithDelta(0.0, (float) $lines[0]->iva, 0.001);
        $this->assertEqualsWithDelta(30.0, (float) $invoice->total, 0.01);
    }

    /**
     * Con varios impuestos al mismo tipo, buscar «el primero que coincida» elegía
     * por orden alfabético y podía cruzar regímenes (IVA4 -> IPSI4).
     */
    public function testFallbackNeverCrossesTaxRegimes(): void
    {
        $default = Impuestos::default();
        $this->assertNotEmpty($default->operacion, 'El impuesto predeterminado debe tener operación');

        // Dos impuestos al mismo tipo y distinto régimen. El señuelo ordena
        // antes alfabéticamente, así que «el primero que coincida» lo elegiría.
        $decoy = $this->createTax('AAA9', $default->operacion === 'ES_04' ? 'ES_01' : 'ES_04');
        $expected = $this->createTax('ZZZ9', $default->operacion);

        $supplier = $this->createSupplier();
        $result = (new InvoiceMapper())->mapToInvoice(
            $this->buildData($supplier, ['codimpuesto' => 'NOEXISTE9', 'iva' => 9.87]),
            null,
            'lines',
            false
        );

        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));

        $invoice = new FacturaProveedor();
        $this->assertTrue($invoice->loadFromCode($result['invoice_id']));
        $this->invoicesToDelete[] = $invoice;

        $this->assertSame($expected->codimpuesto, $invoice->getLines()[0]->codimpuesto);
        $this->assertNotSame($decoy->codimpuesto, $invoice->getLines()[0]->codimpuesto);
    }

    /**
     * Un único candidato no basta: en una instalación estándar el 7 % solo lo
     * tiene IGIC7, que no sirve para una empresa que trabaja con IVA.
     */
    public function testFallbackRejectsTheOnlyCandidateWhenItIsFromAnotherRegime(): void
    {
        $default = Impuestos::default();
        $this->assertNotEmpty($default->operacion, 'El impuesto predeterminado debe tener operación');

        // Único impuesto a ese tipo en toda la instalación, y de otro régimen.
        $this->createTax('AAA9', $default->operacion === 'ES_04' ? 'ES_01' : 'ES_04');

        $supplier = $this->createSupplier();
        $result = (new InvoiceMapper())->mapToInvoice(
            $this->buildData($supplier, ['codimpuesto' => 'NOEXISTE9', 'iva' => 9.87]),
            null,
            'lines',
            false
        );

        $this->assertFalse($result['success'], 'No se puede aceptar un impuesto de otro régimen fiscal');
        $this->assertNull($result['invoice_id']);
        $this->assertSame(
            0,
            (new FacturaProveedor())->count([Where::eq('codproveedor', $supplier->codproveedor)]),
            'No debe quedar ninguna factura en boceto'
        );
    }

    /**
     * Dos impuestos del mismo régimen y mismo tipo tampoco desempatan.
     */
    public function testFallbackRejectsAmbiguousTaxesInTheSameRegime(): void
    {
        $default = Impuestos::default();
        $this->assertNotEmpty($default->operacion, 'El impuesto predeterminado debe tener operación');

        $this->createTax('AAA8', $default->operacion, 8.76);
        $this->createTax('ZZZ8', $default->operacion, 8.76);

        $supplier = $this->createSupplier();
        $result = (new InvoiceMapper())->mapToInvoice(
            $this->buildData($supplier, ['codimpuesto' => 'NOEXISTE8', 'iva' => 8.76]),
            null,
            'lines',
            false
        );

        $this->assertFalse($result['success'], 'Con dos candidatos del mismo régimen no se puede elegir');
        $this->assertNull($result['invoice_id']);
    }

    public function testUnresolvableTaxAbortsWithoutLeavingADraft(): void
    {
        $supplier = $this->createSupplier();

        // 3,17 % no existe en ninguna instalación, así que no hay candidato.
        $result = (new InvoiceMapper())->mapToInvoice(
            $this->buildData($supplier, ['codimpuesto' => 'NOEXISTE', 'iva' => 3.17]),
            null,
            'lines',
            false
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['invoice_id']);
        $this->assertStringContainsString('NOEXISTE', implode('; ', $result['errors']));
        $this->assertSame(
            0,
            (new FacturaProveedor())->count([Where::eq('codproveedor', $supplier->codproveedor)]),
            'No debe quedar ninguna factura en boceto'
        );
    }

    /**
     * Al reimportar sobre una factura existente se guardaba la cabecera y se
     * borraban sus líneas antes de saber si las nuevas se podían grabar.
     */
    public function testFailedUpdateLeavesTheExistingInvoiceUntouched(): void
    {
        $supplier = $this->createSupplier();

        $created = (new InvoiceMapper())->mapToInvoice(
            $this->buildData($supplier, ['iva' => 0]),
            null,
            'lines',
            false
        );
        $this->assertTrue($created['success'], implode('; ', $created['errors'] ?? []));

        $invoice = new FacturaProveedor();
        $this->assertTrue($invoice->loadFromCode($created['invoice_id']));
        $this->invoicesToDelete[] = $invoice;
        $this->assertCount(1, $invoice->getLines());

        // El import deja la factura como «Recibida» (no editable) si ese estado
        // existe. El caso que interesa es reimportar sobre una que sí se puede
        // editar, que es cuando se llegaba a borrar sus líneas.
        $this->makeEditable($invoice);

        $originalNumber = (string) $invoice->numproveedor;
        $originalDate = (string) $invoice->fecha;
        $originalNotes = (string) $invoice->observaciones;

        $failed = (new InvoiceMapper())->mapToInvoice(
            $this->buildData(
                $supplier,
                ['iva' => 0, 'referencia' => 'REF-DEMASIADO-LARGA-PARA-LA-COLUMNA-DE-30'],
                [
                    'number' => 'NUMERO-QUE-NO-DEBE-PERSISTIR',
                    'issue_date' => '2026-09-30',
                    'summary' => 'Observaciones que no deben persistir',
                ]
            ),
            (int) $created['invoice_id'],
            'lines',
            false
        );

        $this->assertFalse($failed['success']);

        $reloaded = new FacturaProveedor();
        $this->assertTrue($reloaded->loadFromCode($created['invoice_id']));

        $lines = $reloaded->getLines();
        $this->assertCount(1, $lines, 'La factura existente no puede quedarse sin líneas');
        $this->assertSame('ONA MESITA NOCHE 1 CAJON 1 HUECO BLANCO', $lines[0]->descripcion);
        $this->assertEqualsWithDelta(30.0, (float) $reloaded->total, 0.01);

        // La cabecera tampoco puede quedarse a medias (issue #93).
        $this->assertSame($originalNumber, (string) $reloaded->numproveedor);
        $this->assertSame($originalDate, (string) $reloaded->fecha);
        $this->assertSame($originalNotes, (string) $reloaded->observaciones);
    }

    public function testOverlongTaxExceptionIsIgnored(): void
    {
        $supplier = $this->createSupplier();

        $result = (new InvoiceMapper())->mapToInvoice(
            $this->buildData($supplier, [
                'codimpuesto' => 'IGIC0',
                'iva' => 0,
                'excepcioniva' => 'Art. 50.Uno.27 de la Ley 4/2012',
            ]),
            null,
            'lines',
            false
        );

        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));

        $invoice = new FacturaProveedor();
        $this->assertTrue($invoice->loadFromCode($result['invoice_id']));
        $this->invoicesToDelete[] = $invoice;

        $this->assertEmpty($invoice->getLines()[0]->excepcioniva);
    }

    public function testValidTaxExceptionIsKept(): void
    {
        $supplier = $this->createSupplier();

        $result = (new InvoiceMapper())->mapToInvoice(
            $this->buildData($supplier, ['iva' => 0, 'excepcioniva' => 'ES_20']),
            null,
            'lines',
            false
        );

        $this->assertTrue($result['success'], implode('; ', $result['errors'] ?? []));

        $invoice = new FacturaProveedor();
        $this->assertTrue($invoice->loadFromCode($result['invoice_id']));
        $this->invoicesToDelete[] = $invoice;

        $this->assertSame('ES_20', $invoice->getLines()[0]->excepcioniva);
    }

    public function testFailedLinesLeaveNoDraftInvoiceBehind(): void
    {
        $supplier = $this->createSupplier();

        $result = (new InvoiceMapper())->mapToInvoice(
            $this->buildData($supplier, [
                'iva' => 0,
                'referencia' => 'REF-DEMASIADO-LARGA-PARA-LA-COLUMNA-DE-30',
            ]),
            null,
            'lines',
            false
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['invoice_id']);

        $where = [Where::eq('codproveedor', $supplier->codproveedor)];
        $this->assertSame(
            0,
            (new FacturaProveedor())->count($where),
            'No debe quedar ninguna factura en boceto cuando fallan las líneas'
        );
    }

    private function buildData(Proveedor $supplier, array $line, array $invoice = []): array
    {
        return [
            'invoice' => array_merge([
                'number' => 'RFAC-' . mt_rand(10000, 99999),
                'issue_date' => '2026-08-25',
                'currency' => 'EUR',
                'subtotal' => 30.0,
                'tax_amount' => 0.0,
                'total' => 30.0,
            ], $invoice),
            'supplier' => [
                'matched_supplier_id' => $supplier->codproveedor,
                'match_status' => 'matched',
            ],
            'lines' => [array_merge([
                'descripcion' => 'ONA MESITA NOCHE 1 CAJON 1 HUECO BLANCO',
                'cantidad' => 2,
                'pvpunitario' => 15.0,
                'dtopor' => 0,
            ], $line)],
        ];
    }

    private function makeEditable(FacturaProveedor $invoice): void
    {
        $where = [Where::eq('tipodoc', 'FacturaProveedor')];
        foreach ((new EstadoDocumento())->all($where, [], 0, 0) as $status) {
            if ($status->editable) {
                $invoice->idestado = $status->idestado;
                $this->assertTrue($invoice->save(), 'No se pudo devolver la factura a un estado editable');
                return;
            }
        }

        $this->markTestSkipped('No hay un estado editable para FacturaProveedor.');
    }

    private function createTax(string $code, string $operation, float $rate = 9.87): Impuesto
    {
        $tax = new Impuesto();
        $tax->codimpuesto = $code;
        $tax->descripcion = 'AiScan test ' . $code;
        $tax->iva = $rate;
        $tax->recargo = 0.0;
        $tax->operacion = $operation;
        $this->assertTrue($tax->save(), 'No se pudo crear el impuesto de prueba ' . $code);
        $this->taxesToDelete[] = $tax;

        return $tax;
    }

    private function resolvePaymentMethodCode(): string
    {
        $existing = (new FormaPago())->all([], [], 0, 1);
        if (!empty($existing)) {
            return (string) $existing[0]->codpago;
        }

        $method = new FormaPago();
        $method->codpago = 'T' . mt_rand(1000, 9999);
        $method->descripcion = 'AiScan Tax93 test';
        $method->pagado = false;
        $method->plazovencimiento = 0;
        $method->tipovencimiento = 'days';
        $method->activa = true;
        $this->assertTrue($method->save(), 'No se pudo crear la forma de pago de prueba');
        $this->paymentMethodsToDelete[] = $method;

        return (string) $method->codpago;
    }

    private function createSupplier(): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = 'AiScan Tax93 ' . mt_rand(10000, 99999);
        $supplier->razonsocial = $supplier->nombre;
        $supplier->cifnif = 'Y' . mt_rand(10000000, 99999999);
        $supplier->personafisica = false;
        $supplier->codpago = $this->resolvePaymentMethodCode();

        if (empty($supplier->codserie)) {
            $series = (new Serie())->all([], [], 0, 1);
            if (empty($series)) {
                $newSerie = new Serie();
                $newSerie->codserie = 'A';
                $newSerie->descripcion = 'Serie A';
                $newSerie->save();
                $series = [$newSerie];
            }
            $supplier->codserie = $series[0]->codserie;
        }

        $this->assertTrue($supplier->save(), 'No se pudo crear el proveedor de prueba');
        $this->suppliersToDelete[] = $supplier;

        return $supplier;
    }
}
