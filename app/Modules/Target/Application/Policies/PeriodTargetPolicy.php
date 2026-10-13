<?php

declare(strict_types=1);

namespace App\Modules\Target\Application\Policies;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;

/**
 * A target follows the scope of the unit it belongs to: the employee's
 * snapshot home team, or the team/department/company the target is set for.
 *
 * ROLE_APPROVAL_MATRIX "Menetapkan target" is conditional for every role and
 * the conditions are not decided (DEC-002). Until they are, only HR
 * Performance holds target.manage and target.approve.
 */
final readonly class PeriodTargetPolicy
{
    public function __construct(private AccessControl $access) {}

    public function view(User $user, PeriodTarget $target): bool
    {
        return $this->allowsOn($user, Capability::TargetView, $target);
    }

    public function manage(User $user, PeriodTarget $target): bool
    {
        return $this->allowsOn($user, Capability::TargetManage, $target);
    }

    public function approve(User $user, PeriodTarget $target): bool
    {
        return $this->allowsOn($user, Capability::TargetApprove, $target);
    }

    private function allowsOn(User $user, Capability $capability, PeriodTarget $target): bool
    {
        return $this->access->allows($user, $capability, $this->access->unitTarget($target->organization_unit_id));
    }
}
