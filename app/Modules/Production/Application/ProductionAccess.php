<?php

declare(strict_types=1);

namespace App\Modules\Production\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who reads and loads production data. Batches belong to a production team
 * (the `production.department_code` department) or to the PPIC team, and a
 * team loads only the kinds it is responsible for
 * (`production.responsibilities`). Other teams' leaders hold the capability
 * over units that never hold production data.
 *
 * The PPIC board is shared by the PPIC team and the production teams: anyone
 * who reads production data of any of those units sees the board; only the
 * holders of `ppic_board.manage` over the PPIC team move work orders.
 */
final readonly class ProductionAccess
{
    /**
     * @param  array<string, mixed>  $responsibilities  team code => list of batch kinds (configuration)
     */
    public function __construct(
        private AccessControl $access,
        private OrganizationDirectory $directory,
        private string $departmentCode,
        private string $ppicUnitCode,
        private array $responsibilities,
    ) {}

    /**
     * @return list<int>
     */
    public function productionUnitIds(): array
    {
        $snapshot = $this->directory->snapshot($this->access->businessToday());
        $units = [];

        foreach ($snapshot->all() as $unit) {
            if ($unit->code === $this->departmentCode) {
                $units = [...$units, ...$snapshot->subtreeIds($unit->id)];
            } elseif ($unit->code === $this->ppicUnitCode) {
                $units[] = $unit->id;
            }
        }

        return array_values(array_unique($units));
    }

    public function ppicUnitId(): ?int
    {
        foreach ($this->directory->snapshot($this->access->businessToday())->all() as $unit) {
            if ($unit->code === $this->ppicUnitCode) {
                return $unit->id;
            }
        }

        return null;
    }

    /**
     * The batch kinds a team is responsible for.
     *
     * @return list<BatchKind>
     */
    public function kindsFor(int $unitId): array
    {
        foreach ($this->directory->snapshot($this->access->businessToday())->all() as $unit) {
            if ($unit->id === $unitId) {
                $kinds = $this->responsibilities[$unit->code] ?? [];

                return is_array($kinds) ? array_values(array_filter(array_map(
                    static fn (mixed $kind): ?BatchKind => is_string($kind) ? BatchKind::tryFrom($kind) : null,
                    $kinds,
                ))) : [];
            }
        }

        return [];
    }

    public function readScope(User $user): UnitScope
    {
        return $this->within($this->access->unitScope($user, Capability::ProductionDataView));
    }

    public function manageScope(User $user): UnitScope
    {
        return $this->within($this->access->unitScope($user, Capability::ProductionDataManage));
    }

    public function canRead(User $user, ProductionBatch $batch): bool
    {
        return $this->readScope($user)->coversUnit($batch->organization_unit_id);
    }

    public function canManage(User $user, int $unitId, BatchKind $kind): bool
    {
        return in_array($unitId, $this->productionUnitIds(), true)
            && in_array($kind, $this->kindsFor($unitId), true)
            && $this->access->allows($user, Capability::ProductionDataManage, $this->access->unitTarget($unitId));
    }

    public function canViewBoard(User $user): bool
    {
        return ! $this->readScope($user)->isEmpty();
    }

    public function canManageBoard(User $user): bool
    {
        $unitId = $this->ppicUnitId();

        return $unitId !== null && $this->access->allows($user, Capability::PpicBoardManage, $this->access->unitTarget($unitId));
    }

    /**
     * @param  Builder<ProductionBatch>  $query
     */
    public function restrict(Builder $query, User $user): void
    {
        $scope = $this->readScope($user);
        $query->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds);
    }

    private function within(UnitScope $scope): UnitScope
    {
        $production = $this->productionUnitIds();
        $units = $scope->all ? $production : array_values(array_intersect($scope->unitIds, $production));

        return new UnitScope(false, $units, false);
    }
}
