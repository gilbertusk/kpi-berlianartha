<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Policies;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;

final readonly class OrganizationUnitPolicy
{
    public function __construct(private AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, Capability::OrganizationView);
    }

    public function view(User $user, OrganizationUnit $unit): bool
    {
        return $this->access->allows($user, Capability::OrganizationView, $this->access->unitTarget($unit->id));
    }

    /**
     * Without a parent this only answers "may the user create units at all"
     * (for showing the form); the action re-checks with the chosen parent.
     */
    public function create(User $user, ?OrganizationUnit $parent = null): bool
    {
        return $parent === null
            ? $this->access->allows($user, Capability::OrganizationManage)
            : $this->access->allows($user, Capability::OrganizationManage, $this->access->unitTarget($parent->id));
    }

    public function update(User $user, OrganizationUnit $unit): bool
    {
        return $this->access->allows($user, Capability::OrganizationManage, $this->access->unitTarget($unit->id));
    }
}
