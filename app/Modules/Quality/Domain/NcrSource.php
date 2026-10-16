<?php

declare(strict_types=1);

namespace App\Modules\Quality\Domain;

/**
 * Where a nonconformance was found. An NCR refers to its source record and
 * never copies it: an inspection row of a current QC batch, or the
 * reference of a complaint, return, or internal finding in its own system.
 */
enum NcrSource: string
{
    case Inspection = 'inspection';
    case Complaint = 'complaint';
    case ReturnGoods = 'return';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Inspection => 'Inspeksi QC',
            self::Complaint => 'Complaint customer',
            self::ReturnGoods => 'Return customer',
            self::Internal => 'Temuan internal',
        };
    }
}
