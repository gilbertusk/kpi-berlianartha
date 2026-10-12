<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Application\Policies;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;

/**
 * KPI dictionary access follows the owning unit's scope. Publishing also
 * requires a reviewer other than the submitter (checked by the service).
 */
final readonly class KpiDefinitionPolicy
{
    public function __construct(private AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, Capability::KpiDefinitionView);
    }

    public function view(User $user, KpiDefinition $definition): bool
    {
        return $this->allowsOn($user, Capability::KpiDefinitionView, $definition->owner_unit_id);
    }

    public function create(User $user, ?OrganizationUnit $ownerUnit = null): bool
    {
        return $ownerUnit === null
            ? $this->access->allows($user, Capability::KpiDefinitionManage)
            : $this->allowsOn($user, Capability::KpiDefinitionManage, $ownerUnit->id);
    }

    public function update(User $user, KpiDefinition $definition): bool
    {
        return $this->allowsOn($user, Capability::KpiDefinitionManage, $definition->owner_unit_id);
    }

    public function publish(User $user, KpiDefinition $definition): bool
    {
        return $this->allowsOn($user, Capability::KpiDefinitionPublish, $definition->owner_unit_id);
    }

    private function allowsOn(User $user, Capability $capability, int $unitId): bool
    {
        return $this->access->allows($user, $capability, $this->access->unitTarget($unitId));
    }
}
