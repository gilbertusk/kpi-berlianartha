<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Application;

use App\Modules\PeriodAssignment\Application\PeriodLifecycleParticipant;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Scoring\Domain\ScorecardStatus;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Shared\Application\Audit\Actor;

/**
 * A period enters review only when every covered subject has a complete
 * latest calculation: reviewers must never be handed a partial score.
 */
final class ScoringReadiness implements PeriodLifecycleParticipant
{
    public function periodOpened(AssessmentPeriod $period, Actor $actor): void {}

    public function periodAdvanced(AssessmentPeriod $period, PeriodStatus $from, Actor $actor): void {}

    public function blockersFor(AssessmentPeriod $period, PeriodStatus $next): array
    {
        if ($next !== PeriodStatus::Review) {
            return [];
        }

        $incomplete = count(self::subjectsWithoutCompleteCalculation($period));

        return $incomplete === 0 ? [] : ["{$incomplete} scorecard belum memiliki perhitungan lengkap."];
    }

    /**
     * @return list<int> period assignment ids
     */
    public static function subjectsWithoutCompleteCalculation(AssessmentPeriod $period): array
    {
        $latest = [];

        foreach (ScorecardCalculation::query()->where('assessment_period_id', $period->id)->orderBy('sequence_number')->get(['period_assignment_id', 'status']) as $calculation) {
            $latest[$calculation->period_assignment_id] = $calculation->status;
        }

        $missing = [];

        foreach (PeriodAssignment::query()->where('assessment_period_id', $period->id)->where('coverage', Coverage::Covered->value)->orderBy('id')->pluck('id') as $assignmentId) {
            if (($latest[(int) $assignmentId] ?? null) !== ScorecardStatus::Complete) {
                $missing[] = (int) $assignmentId;
            }
        }

        return $missing;
    }
}
