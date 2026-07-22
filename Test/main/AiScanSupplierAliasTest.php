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

use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Plugins\AiScan\Lib\SupplierMatcher;
use FacturaScripts\Plugins\AiScan\Model\AiScanSupplierAlias;
use PHPUnit\Framework\TestCase;

/**
 * Issue #71: memoria de alias de proveedor aprendida por corrección del usuario.
 */
final class AiScanSupplierAliasTest extends TestCase
{
    /** @var Proveedor[] */
    private array $suppliersToDelete = [];

    /** @var string[] */
    private array $fingerprintsToClear = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(Proveedor::class)) {
            $this->markTestSkipped('FacturaScripts Dinamic\Model\Proveedor no disponible en este entorno.');
        }
        // Materializa la tabla en CI Docker (plugin copiado sin deploy previo).
        new AiScanSupplierAlias();
    }

    protected function tearDown(): void
    {
        foreach ($this->fingerprintsToClear as $fp) {
            $alias = AiScanSupplierAlias::getForFingerprint($fp);
            if ($alias) {
                $alias->delete();
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
    }

    /**
     * @testdox computeFingerprint prioriza tax_id normalizado sobre el nombre
     */
    public function testFingerprintPrefersNormalizedTaxId(): void
    {
        $fp = AiScanSupplierAlias::computeFingerprint([
            'tax_id' => 'ES B-12.345.678',
            'name' => 'Acme SL',
        ]);

        $this->assertNotNull($fp);
        $this->assertSame(AiScanSupplierAlias::TYPE_TAX_ID, $fp['type']);
        $this->assertSame('tax_id:B12345678', $fp['fingerprint']);
    }

    /**
     * @testdox Sin CIF se usa el nombre normalizado como huella
     */
    public function testFingerprintFallsBackToName(): void
    {
        $fp = AiScanSupplierAlias::computeFingerprint([
            'tax_id' => '',
            'name' => '  Acme   S.L.  ',
        ]);

        $this->assertNotNull($fp);
        $this->assertSame(AiScanSupplierAlias::TYPE_NAME, $fp['type']);
        $this->assertSame('name:acme', $fp['fingerprint']);
    }

    /**
     * @testdox Sin datos útiles no hay huella
     */
    public function testFingerprintNullWhenEmpty(): void
    {
        $this->assertNull(AiScanSupplierAlias::computeFingerprint([]));
        $this->assertNull(AiScanSupplierAlias::computeFingerprint([
            'tax_id' => '  ',
            'name' => '',
        ]));
    }

    /**
     * @testdox Tras una corrección del usuario, el siguiente match usa el alias (#71)
     */
    public function testRememberAndResolveViaMatcher(): void
    {
        $supplier = $this->createSupplier('Alias Learn ' . mt_rand(1000, 9999), 'Z' . mt_rand(10000000, 99999999));

        // Datos "extraídos" con CIF en formato distinto (no exacto al almacenado).
        // Aunque el matching por CIF normalizado podría acertar, simulamos un
        // nombre comercial que no matchea y un tax_id que usamos como huella.
        $extracted = [
            'name' => 'Nombre Comercial Inventado XYZ',
            'tax_id' => $supplier->cifnif,
        ];

        $fp = AiScanSupplierAlias::computeFingerprint($extracted);
        $this->assertNotNull($fp);
        $this->fingerprintsToClear[] = $fp['fingerprint'];

        $this->assertTrue(
            AiScanSupplierAlias::rememberFromSupplierData($extracted, $supplier->codproveedor)
        );

        $matcher = new SupplierMatcher();
        $result = $matcher->findMatch($extracted);

        $this->assertSame('matched', $result['match_status']);
        $this->assertSame('alias', $result['match_source']);
        $this->assertSame($supplier->codproveedor, $result['supplier']->codproveedor);
    }

    /**
     * @testdox Auto-match no escribe alias: solo rememberFromSupplierData lo hace
     */
    public function testAutoMatchDoesNotWriteAlias(): void
    {
        $cif = 'Y' . mt_rand(10000000, 99999999);
        $supplier = $this->createSupplier('No Write Alias ' . mt_rand(1000, 9999), $cif);

        $matcher = new SupplierMatcher();
        $result = $matcher->findMatch([
            'name' => $supplier->nombre,
            'tax_id' => $cif,
        ]);
        $this->assertSame('matched', $result['match_status']);
        // No debe provenir de alias (aún no hay fila)
        $this->assertNotSame('alias', $result['match_source'] ?? null);

        $fp = AiScanSupplierAlias::computeFingerprint(['tax_id' => $cif]);
        $this->assertNotNull($fp);
        $this->assertNull(AiScanSupplierAlias::getForFingerprint($fp['fingerprint']));
    }

    /**
     * @testdox Alias de proveedor borrado se ignora y se limpia
     */
    public function testStaleAliasIsDropped(): void
    {
        $supplier = $this->createSupplier('Stale Alias ' . mt_rand(1000, 9999), 'X' . mt_rand(10000000, 99999999));
        $cod = $supplier->codproveedor;
        $extracted = ['tax_id' => $supplier->cifnif, 'name' => 'Stale'];

        $fp = AiScanSupplierAlias::computeFingerprint($extracted);
        $this->assertNotNull($fp);
        $this->fingerprintsToClear[] = $fp['fingerprint'];
        $this->assertTrue(AiScanSupplierAlias::rememberFromSupplierData($extracted, $cod));

        // Borrar proveedor (y su dirección)
        $address = $supplier->getDefaultAddress();
        if ($address->exists()) {
            $address->delete();
        }
        $this->assertTrue($supplier->delete());
        // Evitar doble delete en tearDown
        $this->suppliersToDelete = array_values(array_filter(
            $this->suppliersToDelete,
            static fn (Proveedor $s): bool => $s->codproveedor !== $cod
        ));

        $resolved = AiScanSupplierAlias::resolveSupplier($extracted);
        $this->assertNull($resolved);
        $this->assertNull(AiScanSupplierAlias::getForFingerprint($fp['fingerprint']));
    }

    private function createSupplier(string $name, string $cifnif): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = $name;
        $supplier->razonsocial = $name;
        $supplier->cifnif = $cifnif;
        $supplier->personafisica = false;
        $this->assertTrue($supplier->save(), 'No se pudo crear proveedor de prueba');
        $this->suppliersToDelete[] = $supplier;

        return $supplier;
    }
}
