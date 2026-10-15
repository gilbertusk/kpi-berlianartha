<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Models\User;
use App\Modules\Accounting\Infrastructure\Persistence\Models\ArSnapshot;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Organization\Application\OrganizationDirectory;
use Illuminate\Database\Eloquent\Builder;

/**
 * Least privilege for financial and customer data: a snapshot belongs to an
 * Accounting unit and is readable only in the `accounting_data.view` scope
 * over that unit (Management, the Accounting department and its team,
 * Auditor). HR Performance has no access, and other teams' leaders hold the
 * capability over units that never hold accounting data.
 */
final readonly class ArAccess
{
    public function __construct(
        private AccessControl $access,
        private OrganizationDirectory $directory,
        private string $departmentCode,
    ) {}

    /**
     * The units that hold accounting data: the Accounting department
     * (`accounting.department_code`) and its teams.
     *
     * @return list<int>
     */
    public function accountingUnitIds(): array
    {
        $snapshot = $this->directory->snapshot($this->access->businessToday());

        foreach ($snapshot->all() as $unit) {
            if ($unit->code === $this->departmentCode) {
                return $snapshot->subtreeIds($unit->id);
            }
        }

        return [];
    }

    /**
     * The `accounting_data.view` scope, limited to the Accounting units: a
     * Team Leader of another team holds the capability but no accounting data.
     */
    public function readScope(User $user): UnitScope
    {
        return $this->within($this->access->unitScope($user, Capability::AccountingDataView));
    }

    public function manageScope(User $user): UnitScope
    {
        return $this->within($this->access->unitScope($user, Capability::AccountingDataManage));
    }

    public function canRead(User $user, ArSnapshot $snapshot): bool
    {
        return $this->readScope($user)->coversUnit($snapshot->organization_unit_id);
    }

    public function canManage(User $user, int $unitId): bool
    {
        return in_array($unitId, $this->accountingUnitIds(), true)
            && $this->access->allows($user, Capability::AccountingDataManage, $this->access->unitTarget($unitId));
    }

    private function within(UnitScope $scope): UnitScope
    {
        $accounting = $this->accountingUnitIds();
        $units = $scope->all ? $accounting : array_values(array_intersect($scope->unitIds, $accounting));

        return new UnitScope(false, $units, false);
    }

    /**
     * @param  Builder<ArSnapshot>  $query
     */
    public function restrict(Builder $query, User $user): void
    {
        $scope = $this->readScope($user);

        if (! $scope->all) {
            $query->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds);
        }
    }
}
