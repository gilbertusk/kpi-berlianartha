<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Policies;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\Delegation;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use Carbon\CarbonImmutable;

/**
 * A holder may delegate their own approval-chain role. HR Performance may
 * administer delegations inside its scope. Oversight roles only read.
 */
final readonly class DelegationPolicy
{
    public function __construct(private AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, Capability::DelegationView)
            || $this->access->allows($user, Capability::DelegationManage)
            || $this->access->grants($user)->delegableAssignments() !== []
            || Delegation::query()
                ->where(fn ($query) => $query->where('delegator_user_id', $user->id)->orWhere('delegate_user_id', $user->id))
                ->exists();
    }

    public function view(User $user, Delegation $delegation): bool
    {
        return $delegation->delegator_user_id === $user->id
            || $delegation->delegate_user_id === $user->id
            || $this->access->allows($user, Capability::DelegationView, $this->access->unitTarget($delegation->organization_unit_id));
    }

    public function create(User $user, ?RoleAssignment $source = null): bool
    {
        if ($source === null) {
            return $this->access->grants($user)->delegableAssignments() !== []
                || $this->access->allows($user, Capability::DelegationManage);
        }

        if (! $source->role->isDelegable()) {
            return false;
        }

        if ($source->user_id === $user->id) {
            return $user->isActive()
                && $source->isActiveAt($this->access->businessToday(), CarbonImmutable::now());
        }

        return $source->organization_unit_id !== null
            && $this->access->allows($user, Capability::DelegationManage, $this->access->unitTarget($source->organization_unit_id));
    }

    public function revoke(User $user, Delegation $delegation): bool
    {
        return ($delegation->delegator_user_id === $user->id && $user->isActive())
            || $this->access->allows($user, Capability::DelegationManage, $this->access->unitTarget($delegation->organization_unit_id));
    }
}
