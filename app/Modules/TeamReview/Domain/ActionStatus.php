<?php

declare(strict_types=1);

namespace App\Modules\TeamReview\Domain;

/**
 * Lifecycle of a corrective action (PRD §9.2): OPEN → IN_PROGRESS ⇄ WAITING,
 * IN_PROGRESS → VERIFIED → CLOSED. The owner reports completion while the
 * action is in progress; someone else verifies it. A closed action is final.
 */
enum ActionStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Verified = 'verified';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::Waiting => 'Waiting',
            self::Verified => 'Verified',
            self::Closed => 'Closed',
        };
    }

    /**
     * Still needs work, so its due date matters.
     */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Open, self::InProgress, self::Waiting], true);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::Open->value => [self::InProgress->value],
            self::InProgress->value => [self::Waiting->value, self::Verified->value],
            self::Waiting->value => [self::InProgress->value],
            self::Verified->value => [self::Closed->value],
            self::Closed->value => [],
        ];
    }
}
