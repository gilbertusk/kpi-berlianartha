<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\PeriodAssignment\Application\PeriodVisibility;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;

/**
 * Whether a period holds anything an account may use an actual capability
 * on: units of its scope, or targets of its own employee record.
 */
final readonly class ActualPeriodAccess
{
    public function __construct(
        private AccessControl $access,
        private PeriodVisibility $visibility,
    ) {}

    public function scope(User $user, Capability $capability): ActualAccess
    {
        return ActualAccess::for($this->access, $user, $capability);
    }

    public function concerns(User $user, Capability $capability, AssessmentPeriod $period): bool
    {
        $scope = $this->scope($user, $capability);

        if ($scope->isEmpty()) {
            return false;
        }

        if ($scope->units->reachesOthers() && $this->visibility->concerns($scope->units, $period, $this->access->businessToday())) {
            return true;
        }

        return $scope->ownEmployeeId !== null && PeriodTarget::query()
            ->where('assessment_period_id', $period->id)
            ->where('employee_id', $scope->ownEmployeeId)
            ->exists();
    }

    public function allowsTarget(User $user, Capability $capability, PeriodTarget $target): bool
    {
        return $this->scope($user, $capability)->covers($target);
    }
}
