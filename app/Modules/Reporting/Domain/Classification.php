<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * Handling label printed on every export. The company has no approved
 * classification scheme yet (DEC-010), so these two labels are a working
 * distinction, not a policy: "restricted" marks a report that names
 * individual employees or carries their scores, notes, or audit detail.
 */
enum Classification: string
{
    case Internal = 'internal';
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal',
            self::Restricted => 'Terbatas — memuat data individu',
        };
    }
}
