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

namespace FacturaScripts\Plugins\AiScan\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Plugins\AiScan\Lib\SupplierMatcher;

/**
 * Memoria determinista de alias de proveedor (issue #71).
 *
 * Guarda el codproveedor que el usuario eligió para una huella extraída
 * (CIF normalizado, nombre, IBAN o email) y lo reutiliza en imports futuros
 * antes del matching difuso.
 */
class AiScanSupplierAlias extends ModelClass
{
    use ModelTrait;

    public const TYPE_TAX_ID = 'tax_id';
    public const TYPE_NAME = 'name';
    public const TYPE_IBAN = 'iban';
    public const TYPE_EMAIL = 'email';

    public ?int $id;
    public ?string $fingerprint;
    public ?string $fingerprint_type;
    public ?string $codproveedor;
    public ?string $created_at;

    public function clear(): void
    {
        parent::clear();
        $this->id = 0;
        $this->fingerprint = '';
        $this->fingerprint_type = self::TYPE_TAX_ID;
        $this->codproveedor = '';
        $this->created_at = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'aiscan_supplier_aliases';
    }

    public function test(): bool
    {
        if (empty($this->fingerprint) || empty($this->codproveedor) || empty($this->fingerprint_type)) {
            return false;
        }
        return parent::test();
    }

    /**
     * Calcula la mejor huella disponible a partir de datos extraídos.
     *
     * Prioridad: tax_id normalizado > name normalizado > iban > email.
     *
     * @param array{tax_id?: string|null, name?: string|null, iban?: string|null, email?: string|null} $supplierData
     *
     * @return array{fingerprint: string, type: string}|null
     */
    public static function computeFingerprint(array $supplierData): ?array
    {
        $taxId = SupplierMatcher::normalizeTaxId((string) ($supplierData['tax_id'] ?? ''));
        if ($taxId !== '') {
            return [
                'fingerprint' => self::TYPE_TAX_ID . ':' . $taxId,
                'type' => self::TYPE_TAX_ID,
            ];
        }

        $name = self::normalizeName((string) ($supplierData['name'] ?? ''));
        if ($name !== '') {
            return [
                'fingerprint' => self::TYPE_NAME . ':' . $name,
                'type' => self::TYPE_NAME,
            ];
        }

        $iban = self::normalizeIban((string) ($supplierData['iban'] ?? ''));
        if ($iban !== '') {
            return [
                'fingerprint' => self::TYPE_IBAN . ':' . $iban,
                'type' => self::TYPE_IBAN,
            ];
        }

        $email = self::normalizeEmail((string) ($supplierData['email'] ?? ''));
        if ($email !== '') {
            return [
                'fingerprint' => self::TYPE_EMAIL . ':' . $email,
                'type' => self::TYPE_EMAIL,
            ];
        }

        return null;
    }

    public static function getForFingerprint(string $fingerprint): ?self
    {
        $fingerprint = trim($fingerprint);
        if ($fingerprint === '') {
            return null;
        }

        $where = [new DataBaseWhere('fingerprint', $fingerprint)];
        $found = self::all($where, [], 0, 1);
        return $found[0] ?? null;
    }

    /**
     * Resuelve un proveedor vivo a partir de la huella. Si el alias apunta a un
     * proveedor borrado, se elimina el alias huérfano y se devuelve null.
     */
    public static function resolveSupplier(array $supplierData): ?Proveedor
    {
        $fp = self::computeFingerprint($supplierData);
        if ($fp === null) {
            return null;
        }

        $alias = self::getForFingerprint($fp['fingerprint']);
        if ($alias === null || empty($alias->codproveedor)) {
            return null;
        }

        $supplier = new Proveedor();
        if ($supplier->loadFromCode($alias->codproveedor)) {
            return $supplier;
        }

        // Alias obsoleto: proveedor borrado o fusionado.
        $alias->delete();
        return null;
    }

    /**
     * Solo debe llamarse ante una elección explícita del usuario (nunca auto-match).
     */
    public static function setForFingerprint(
        string $fingerprint,
        string $fingerprintType,
        string $codproveedor
    ): bool {
        $fingerprint = trim($fingerprint);
        $codproveedor = trim($codproveedor);
        $fingerprintType = trim($fingerprintType);
        if ($fingerprint === '' || $codproveedor === '' || $fingerprintType === '') {
            return false;
        }

        $existing = self::getForFingerprint($fingerprint);
        if ($existing) {
            $existing->codproveedor = $codproveedor;
            $existing->fingerprint_type = $fingerprintType;
            return $existing->save();
        }

        $model = new self();
        $model->fingerprint = $fingerprint;
        $model->fingerprint_type = $fingerprintType;
        $model->codproveedor = $codproveedor;
        return $model->save();
    }

    /**
     * Atajo: calcula huella desde datos extraídos y guarda el codproveedor elegido.
     */
    public static function rememberFromSupplierData(array $supplierData, string $codproveedor): bool
    {
        $fp = self::computeFingerprint($supplierData);
        if ($fp === null) {
            return false;
        }

        return self::setForFingerprint($fp['fingerprint'], $fp['type'], $codproveedor);
    }

    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        if ($name === '') {
            return '';
        }

        // Quitar formas societarias frecuentes para estabilizar la clave.
        $name = preg_replace(
            '/\s*\b(s\.?r\.?l\.?|s\.?l\.?u\.?|s\.?a\.?u\.?|s\.?l\.?l\.?|s\.?l\.?|s\.?a\.?|s\.?c\.?)\.?\s*/iu',
            ' ',
            $name
        ) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        return trim($name);
    }

    public static function normalizeIban(string $iban): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', trim($iban)) ?? '');
        return $iban;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
