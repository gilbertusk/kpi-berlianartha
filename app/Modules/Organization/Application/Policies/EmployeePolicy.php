<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Policies;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;

final readonly class EmployeePolicy
{
    public function __construct(private AccessControl $access) {}

    /**
     * The employee directory is only for roles that see other people; an
     * Employee-only account may still open its own record directly.
     */
    public function viewAny(User $user): bool
    {
        return $this->access->unitScope($user, Capability::EmployeeView)->reachesOthers();
    }

    public function view(User $user, Employee $employee): bool
    {
        return $this->access->allows($user, Capability::EmployeeView, $this->access->employeeTarget($employee));
    }

    public function create(User $user, ?OrganizationUnit $homeUnit = null): bool
    {
        if ($homeUnit === null) {
            return $this->access->allows($user, Capability::EmployeeManage);
        }

        return $this->access->allows($user, Capability::EmployeeManage, $this->access->unitTarget($homeUnit->id));
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->access->allows($user, Capability::EmployeeManage, $this->access->employeeTarget($employee));
    }

    /**
     * Moving someone requires authority over the destination as well.
     */
    public function assign(User $user, Employee $employee, OrganizationUnit $unit): bool
    {
        return $this->update($user, $employee)
            && $this->access->allows($user, Capability::EmployeeManage, $this->access->unitTarget($unit->id));
    }
}
