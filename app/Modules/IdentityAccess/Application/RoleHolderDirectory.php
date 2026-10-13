<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use Carbon\CarbonImmutable;

/**
 * Public read contract: who holds organization-scoped roles on a business
 * date. Other modules use it to record an approver chain instead of reading
 * role tables themselves (DOMAIN_BOUNDARIES.md §3). Delegations are not
 * included; they are time-bounded and evaluated when a decision is made.
 */
final class RoleHolderDirectory
{
    /**
     * Assignments active on the business date, not revoked at the instant,
     * and owned by an active account.
     *
     * @param  list<Role>  $roles
     * @return list<RoleHolder>
     */
    public function activeHolders(array $roles, string $businessDate, CarbonImmutable $at): array
    {
        $assignments = RoleAssignment::query()
            ->with('user')
            ->whereIn('role', array_map(static fn (Role $role): string => $role->value, $roles))
            ->whereNotNull('organization_unit_id')
            ->activeAt($businessDate, $at)
            ->orderBy('id')
            ->get();

        $holders = [];

        foreach ($assignments as $assignment) {
            if ($assignment->organization_unit_id === null || ! $assignment->user->isActive()) {
                continue;
            }

            $holders[] = new RoleHolder(
                role: $assignment->role,
                userId: $assignment->user_id,
                userName: $assignment->user->name,
                unitId: $assignment->organization_unit_id,
                assignmentPublicId: $assignment->public_id,
            );
        }

        return $holders;
    }
}
