<?php
/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\AiScan\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;

class AiScanLog extends ModelClass
{
    use ModelTrait;

    public int $id;
    public ?int $idfactura;
    public string $filename;
    public string $mime_type;
    public string $provider;
    public ?string $model;
    public string $status;
    public ?string $raw_payload;
    public ?string $error_message;
    public float $confidence;
    public string $created_at;

    public function clear(): void
    {
        parent::clear();
        $this->id = 0;
        $this->status = 'pending';
        $this->confidence = 0.0;
        $this->created_at = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'aiscan_logs';
    }

    public function test(): bool
    {
        if (empty($this->filename)) {
            $this->toolBox()->i18nLog()->warning('aiscan-filename-required');
            return false;
        }
        return parent::test();
    }
}
