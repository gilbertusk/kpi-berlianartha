<?php

declare(strict_types=1);

namespace App\Modules\TeamReview\Domain;

/**
 * A label the team gives an action. It orders lists; no deadline or
 * escalation is derived from it (DEC-009).
 */
enum ActionPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Rendah',
            self::Medium => 'Sedang',
            self::High => 'Tinggi',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::High => 0,
            self::Medium => 1,
            self::Low => 2,
        };
    }
}
