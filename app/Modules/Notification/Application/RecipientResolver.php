<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Modules\IdentityAccess\Application\RoleHolderDirectory;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\RoleCapabilityMatrix;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Shared\Application\Time\BusinessTime;
use Carbon\CarbonImmutable;

/**
 * Who should be told: active holders of a role or capability whose scope
 * covers a unit today. A notification only points at a record; whether the
 * recipient may open it is decided again by the page.
 */
final readonly class RecipientResolver
{
    public function __construct(
        private RoleHolderDirectory $holders,
        private RoleCapabilityMatrix $matrix,
        private OrganizationDirectory $directory,
        private BusinessTime $time,
    ) {}

    /**
     * @return list<int> user ids
     */
    public function withCapability(Capability $capability, int $unitId): array
    {
        $roles = array_values(array_filter(Role::cases(), fn (Role $role): bool => $this->matrix->allows($role, $capability)));

        return $this->holding($roles, $unitId);
    }

    /**
     * @return list<int> user ids
     */
    public function withRole(Role $role, int $unitId): array
    {
        return $this->holding([$role], $unitId);
    }

    /**
     * Holders of the role whose own unit lies inside the given unit: the
     * audience of something that concerns a whole scope, such as a period.
     *
     * @return list<int> user ids
     */
    public function withRoleInside(Role $role, int $scopeUnitId): array
    {
        return $this->holding([$role], $scopeUnitId, inside: true);
    }

    /**
     * @param  list<Role>  $roles
     * @return list<int>
     */
    private function holding(array $roles, int $unitId, bool $inside = false): array
    {
        if ($roles === []) {
            return [];
        }

        $now = CarbonImmutable::now();
        $today = $this->time->businessDate($now);
        $snapshot = $this->directory->snapshot($today);
        $userIds = [];

        foreach ($this->holders->activeHolders($roles, $today, $now) as $holder) {
            $matches = $inside ? $snapshot->contains($unitId, $holder->unitId) : $snapshot->contains($holder->unitId, $unitId);

            if ($matches) {
                $userIds[$holder->userId] = true;
            }
        }

        return array_keys($userIds);
    }
}
