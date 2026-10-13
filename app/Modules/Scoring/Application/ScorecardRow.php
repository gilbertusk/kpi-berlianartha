<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Application;

use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;

/**
 * One subject of the reconciliation list and its latest calculation.
 */
final readonly class ScorecardRow
{
    public function __construct(
        public PeriodAssignment $assignment,
        public ?ScorecardCalculation $calculation,
    ) {}

    /**
     * @return 'complete'|'incomplete'|'not_calculated'|'not_covered'
     */
    public function state(): string
    {
        if ($this->assignment->coverage !== Coverage::Covered) {
            return 'not_covered';
        }

        return $this->calculation === null ? 'not_calculated' : $this->calculation->status->value;
    }
}
