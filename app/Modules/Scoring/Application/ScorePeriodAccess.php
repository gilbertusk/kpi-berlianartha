<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\PeriodAssignment\Application\PeriodVisibility;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;

/**
 * Whether a period holds a scorecard an account may see or calculate:
 * units of its scope, or its own scorecard.
 */
final readonly class ScorePeriodAccess
{
    public function __construct(
        private AccessControl $access,
        private PeriodVisibility $visibility,
    ) {}

    public function viewScope(User $user): ScoreAccess
    {
        return ScoreAccess::forViewing($this->access, $user);
    }

    public function calculateScope(User $user): ScoreAccess
    {
        return ScoreAccess::forCalculating($this->access, $user);
    }

    public function canView(User $user, AssessmentPeriod $period): bool
    {
        $scope = $this->viewScope($user);

        if ($scope->isEmpty()) {
            return false;
        }

        $today = $this->access->businessToday();

        if ($this->visibility->concerns($scope->units, $period, $today) || $this->visibility->concerns($scope->individuals, $period, $today)) {
            return true;
        }

        return $scope->ownEmployeeId !== null && PeriodAssignment::query()
            ->where('assessment_period_id', $period->id)
            ->where('employee_id', $scope->ownEmployeeId)
            ->exists();
    }

    public function canCalculate(User $user, AssessmentPeriod $period): bool
    {
        return $this->visibility->concerns(
            $this->access->unitScope($user, Capability::ScorecardCalculate),
            $period,
            $this->access->businessToday(),
        );
    }
}
