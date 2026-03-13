<?php

/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Plugins\AiScan\Lib\SupplierMatcher;
use FacturaScripts\Plugins\AiScan\Lib\SupplierService;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
final class SupplierServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (false === class_exists('FacturaScripts\\Dinamic\\Model\\Proveedor', false)) {
            eval(
                'namespace FacturaScripts\Dinamic\Model;'
                . ' class Proveedor {'
                . ' public string $codproveedor = "";'
                . ' public string $nombre = "";'
                . ' public string $razonsocial = "";'
                . ' public string $cifnif = "";'
                . ' public string $email = "";'
                . ' public string $telefono1 = "";'
                . ' public string $observaciones = "";'
                . ' public bool $personafisica = false;'
                . ' public function loadFromCode(string $code): bool { return false; }'
                . ' public function save(): bool { return true; }'
                . ' }'
            );
        }
    }

    public function testUsesMatchedSupplierBeforeCreateFallback(): void
    {
        $matchedSupplier = $this->stubSupplier('SUP-01', 'Acme S.L.');
        $matcher = $this->createMock(SupplierMatcher::class);
        $matcher->expects($this->once())
            ->method('findMatch')
            ->willReturn([
                'match_status' => 'matched',
                'supplier' => $matchedSupplier,
                'candidates' => [],
            ]);

        $service = new class ($matcher) extends SupplierService {
            public bool $createCalled = false;

            protected function createSupplier(array $supplierData): ?Proveedor
            {
                $this->createCalled = true;
                return null;
            }
        };

        $supplierData = [
            'name' => 'Acme S.L.',
            'tax_id' => 'B12345678',
            'create_if_missing' => true,
        ];

        $resolved = $service->resolve($supplierData);

        $this->assertSame($matchedSupplier, $resolved);
        $this->assertFalse($service->createCalled);
        $this->assertSame('SUP-01', $supplierData['matched_supplier_id']);
    }

    public function testCreatesSupplierOnlyWhenLookupFails(): void
    {
        $createdSupplier = $this->stubSupplier('SUP-02', 'New Supplier');
        $matcher = $this->createMock(SupplierMatcher::class);
        $matcher->expects($this->once())
            ->method('findMatch')
            ->willReturn([
                'match_status' => 'not_found',
                'supplier' => null,
                'candidates' => [],
            ]);

        $service = new class ($matcher, $createdSupplier) extends SupplierService {
            public function __construct(
                SupplierMatcher $matcher,
                private Proveedor $createdSupplier
            ) {
                parent::__construct($matcher);
            }

            protected function createSupplier(array $supplierData): ?Proveedor
            {
                return $this->createdSupplier;
            }
        };

        $supplierData = [
            'name' => 'New Supplier',
            'tax_id' => 'B87654321',
            'create_if_missing' => true,
        ];

        $resolved = $service->resolve($supplierData);

        $this->assertSame($createdSupplier, $resolved);
        $this->assertSame('created', $supplierData['match_status']);
        $this->assertSame('SUP-02', $supplierData['matched_supplier_id']);
        $this->assertSame('New Supplier', $supplierData['matched_name']);
    }

    public function testCreateOrResolveEnablesCreateFallback(): void
    {
        $createdSupplier = $this->stubSupplier('SUP-03', 'Created Supplier');
        $matcher = $this->createMock(SupplierMatcher::class);
        $matcher->expects($this->once())
            ->method('findMatch')
            ->willReturn([
                'match_status' => 'not_found',
                'supplier' => null,
                'candidates' => [],
            ]);

        $service = new class ($matcher, $createdSupplier) extends SupplierService {
            public function __construct(
                SupplierMatcher $matcher,
                private Proveedor $createdSupplier
            ) {
                parent::__construct($matcher);
            }

            protected function createSupplier(array $supplierData): ?Proveedor
            {
                return $this->createdSupplier;
            }
        };

        $supplierData = [
            'name' => 'Created Supplier',
            'tax_id' => 'B11122233',
        ];

        $resolved = $service->createOrResolve($supplierData);

        $this->assertSame($createdSupplier, $resolved);
        $this->assertTrue($supplierData['create_if_missing']);
        $this->assertSame('SUP-03', $supplierData['matched_supplier_id']);
    }

    private function stubSupplier(string $code, string $name): Proveedor
    {
        $supplier = $this->getMockBuilder(Proveedor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadFromCode', 'save'])
            ->getMock();
        $supplier->codproveedor = $code;
        $supplier->nombre = $name;
        $supplier->razonsocial = $name;

        return $supplier;
    }
}
