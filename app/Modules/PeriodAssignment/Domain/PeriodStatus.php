<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Domain;

/**
 * Lifecycle of an assessment period (prompt Step 07, PRD §6.1):
 * DRAFT → OPEN → IN_PROGRESS → REVIEW → FINALIZED → LOCKED. No transition
 * goes backwards; reopening is a separate, controlled correction (Step 10).
 */
enum PeriodStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Finalized = 'finalized';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open (penetapan target)',
            self::InProgress => 'In progress (pengisian aktual)',
            self::Review => 'Review',
            self::Finalized => 'Finalized',
            self::Locked => 'Locked',
        };
    }

    /**
     * The period setup (dates, scope, type) can only change before opening.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Targets may be entered or revised only while the period is open or in
     * progress. Later changes need the correction process.
     */
    public function acceptsTargetChanges(): bool
    {
        return $this === self::Open || $this === self::InProgress;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::Open->value],
            self::Open->value => [self::InProgress->value],
            self::InProgress->value => [self::Review->value],
            self::Review->value => [self::Finalized->value],
            self::Finalized->value => [self::Locked->value],
            self::Locked->value => [],
        ];
    }
}
