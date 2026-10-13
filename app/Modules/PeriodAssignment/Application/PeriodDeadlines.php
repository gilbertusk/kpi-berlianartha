<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Application;

use App\Modules\PeriodAssignment\Domain\DeadlineStage;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Application\Time\BusinessTime;
use DateTimeInterface;

/**
 * Deadline arithmetic in the period's own timezone. A stage is open through
 * the whole of its due date and closes at the following business midnight.
 */
final class PeriodDeadlines
{
    public function businessDate(AssessmentPeriod $period, DateTimeInterface $at): string
    {
        return (new BusinessTime($period->timezone))->businessDate($at);
    }

    public function hasPassed(AssessmentPeriod $period, DeadlineStage $stage, DateTimeInterface $at): bool
    {
        $dueOn = $period->deadlineFor($stage);

        return $dueOn !== null && $this->businessDate($period, $at) > $dueOn;
    }
}
