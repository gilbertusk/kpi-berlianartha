<?php

declare(strict_types=1);

namespace App\Modules\Production\Domain;

/**
 * Warehouse location categories the PRD distinguishes (Lampiran B). Stock is
 * a reference snapshot from the source, not an inventory ledger.
 */
enum LocationCategory: string
{
    case RawMaterial = 'raw_material';
    case WorkInProcess = 'work_in_process';
    case FinishedGoods = 'finished_goods';
    case RejectQuarantine = 'reject_quarantine';
    case ReturnGoods = 'return';
    case Scrap = 'scrap';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Raw Material',
            self::WorkInProcess => 'Work in Process',
            self::FinishedGoods => 'Finished Goods',
            self::RejectQuarantine => 'Reject/Quarantine',
            self::ReturnGoods => 'Return',
            self::Scrap => 'Scrap',
        };
    }
}
