<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Application\Policies;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\PeriodAssignment\Application\PeriodVisibility;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;

/**
 * ROLE_APPROVAL_MATRIX "Mengelola periode": HR Performance manages, inside
 * its scope; Auditor reads. Management, Head, and Team Leader may open a
 * period that concerns their units, and see only their own scope inside it.
 */
final readonly class AssessmentPeriodPolicy
{
    public function __construct(
        private AccessControl $access,
        private PeriodVisibility $visibility,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, Capability::PeriodView);
    }

    public function view(User $user, AssessmentPeriod $period): bool
    {
        return $this->visibility->concerns(
            $this->access->unitScope($user, Capability::PeriodView),
            $period,
            $this->access->businessToday(),
        );
    }

    public function create(User $user, ?OrganizationUnit $unit = null): bool
    {
        return $unit === null
            ? $this->access->allows($user, Capability::PeriodManage)
            : $this->access->allows($user, Capability::PeriodManage, $this->access->unitTarget($unit->id));
    }

    /**
     * Setup, opening, lifecycle, and deadlines: the whole period scope must be
     * inside the manager's scope.
     */
    public function update(User $user, AssessmentPeriod $period): bool
    {
        return $this->access->allows($user, Capability::PeriodManage, $this->access->unitTarget($period->organization_unit_id));
    }

    public function viewTargets(User $user, AssessmentPeriod $period): bool
    {
        return $this->visibility->concerns(
            $this->access->unitScope($user, Capability::TargetView),
            $period,
            $this->access->businessToday(),
        );
    }

    public function manageTargets(User $user, AssessmentPeriod $period): bool
    {
        return $this->visibility->concerns(
            $this->access->unitScope($user, Capability::TargetManage),
            $period,
            $this->access->businessToday(),
        );
    }

    public function approveTargets(User $user, AssessmentPeriod $period): bool
    {
        return $this->visibility->concerns(
            $this->access->unitScope($user, Capability::TargetApprove),
            $period,
            $this->access->businessToday(),
        );
    }
}
