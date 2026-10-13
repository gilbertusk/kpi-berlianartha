<?php

declare(strict_types=1);

namespace App\Modules\Target\Application;

use App\Modules\Target\Domain\TargetStatus;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTargetVersion;

/**
 * One line of the target matrix: the slot, the final value in force, and
 * the version still being worked on (if any).
 */
final readonly class TargetRow
{
    public function __construct(
        public PeriodTarget $target,
        public ?PeriodTargetVersion $approved,
        public ?PeriodTargetVersion $working,
    ) {}

    /**
     * @return 'missing'|'draft'|'submitted'|'approved'
     */
    public function state(): string
    {
        return match (true) {
            $this->working?->status === TargetStatus::Submitted => 'submitted',
            $this->working !== null => 'draft',
            $this->approved !== null => 'approved',
            default => 'missing',
        };
    }

    /**
     * A draft can be typed over directly; a final target needs a revision.
     */
    public function acceptsDirectEntry(): bool
    {
        return $this->approved === null && $this->working?->status !== TargetStatus::Submitted;
    }
}
