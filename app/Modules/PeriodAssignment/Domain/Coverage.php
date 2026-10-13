<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Domain;

/**
 * Whether a subject in the snapshot has a usable scorecard template. Only
 * covered subjects receive targets; the others stay visible so HR sees
 * exactly who is not assessed in the period and why.
 */
enum Coverage: string
{
    case Covered = 'covered';
    case NoTemplate = 'no_template';
    case KpiNotInForce = 'kpi_not_in_force';

    public function label(): string
    {
        return match ($this) {
            self::Covered => 'Tercakup',
            self::NoTemplate => 'Tanpa template yang berlaku',
            self::KpiNotInForce => 'Template memakai versi KPI yang tidak berlaku',
        };
    }
}
