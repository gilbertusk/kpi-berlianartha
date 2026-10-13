<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Application;

use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Application\Audit\Actor;

/**
 * Lets a module that depends on periods (targets now; actuals, scoring, and
 * workflow later) take part in a period transition inside the same
 * transaction, without the period module depending on it.
 */
interface PeriodLifecycleParticipant
{
    /**
     * Called after the assignment snapshot is stored, before commit.
     */
    public function periodOpened(AssessmentPeriod $period, Actor $actor): void;

    /**
     * Reasons the period may not move to the next status. Empty = no objection.
     *
     * @return list<string>
     */
    public function blockersFor(AssessmentPeriod $period, PeriodStatus $next): array;
}
