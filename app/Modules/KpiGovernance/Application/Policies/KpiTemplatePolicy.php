<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Application\Policies;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplate;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;

/**
 * Scorecard templates follow the template unit's scope (ROLE_APPROVAL_MATRIX
 * "Membuat/mengubah draft template" and "Publish template": HR Performance).
 * The conditional Manager/Team Leader rights stay disabled until their policy
 * is decided.
 */
final readonly class KpiTemplatePolicy
{
    public function __construct(private AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, Capability::KpiTemplateView);
    }

    public function view(User $user, KpiTemplate $template): bool
    {
        return $this->allowsOn($user, Capability::KpiTemplateView, $template->organization_unit_id);
    }

    public function create(User $user, ?OrganizationUnit $unit = null): bool
    {
        return $unit === null
            ? $this->access->allows($user, Capability::KpiTemplateManage)
            : $this->allowsOn($user, Capability::KpiTemplateManage, $unit->id);
    }

    public function update(User $user, KpiTemplate $template): bool
    {
        return $this->allowsOn($user, Capability::KpiTemplateManage, $template->organization_unit_id);
    }

    public function publish(User $user, KpiTemplate $template): bool
    {
        return $this->allowsOn($user, Capability::KpiTemplatePublish, $template->organization_unit_id);
    }

    private function allowsOn(User $user, Capability $capability, int $unitId): bool
    {
        return $this->access->allows($user, $capability, $this->access->unitTarget($unitId));
    }
}
