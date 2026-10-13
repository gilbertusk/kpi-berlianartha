<?php

declare(strict_types=1);

namespace App\Modules\Target\Domain;

/**
 * Lifecycle of one target version: DRAFT → SUBMITTED → APPROVED, with
 * SUBMITTED → DRAFT when the approver returns it. An approved version is
 * final: it is never edited, only superseded by a later approved revision.
 */
enum TargetStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Menunggu persetujuan',
            self::Approved => 'Final',
        };
    }

    /**
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::Submitted->value],
            self::Submitted->value => [self::Draft->value, self::Approved->value],
            self::Approved->value => [],
        ];
    }
}
