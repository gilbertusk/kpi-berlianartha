<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Domain;

/**
 * What an actual entry states. Zero is a value. "Not applicable" is an
 * explicit statement with a reason; how it is scored follows the KPI's
 * missing-data and N/A rules (DEC-004), not this module.
 */
enum EntryKind: string
{
    case Value = 'value';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Value => 'Nilai',
            self::NotApplicable => 'N/A (tidak berlaku)',
        };
    }
}
