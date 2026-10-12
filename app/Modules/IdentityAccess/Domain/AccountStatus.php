<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain;

/**
 * PRD §5.1: a deactivated account cannot sign in, but remains referenced by
 * historical records.
 */
enum AccountStatus: string
{
    case Active = 'active';
    case Deactivated = 'deactivated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Deactivated => 'Nonaktif',
        };
    }
}
