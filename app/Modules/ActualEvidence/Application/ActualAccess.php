<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which targets an account may use one actual capability on: every target
 * of the units in its scope, plus (for a self-scoped grant) the targets of
 * its own employee record. Lists and bulk operations apply it in SQL.
 */
final readonly class ActualAccess
{
    public function __construct(
        public UnitScope $units,
        public ?int $ownEmployeeId,
    ) {}

    public static function for(AccessControl $access, User $user, Capability $capability): self
    {
        $scope = $access->unitScope($user, $capability);

        return new self($scope, $scope->self ? $user->employee?->id : null);
    }

    public static function everything(): self
    {
        return new self(new UnitScope(true, [], false), null);
    }

    public function isEmpty(): bool
    {
        return ! $this->units->reachesOthers() && $this->ownEmployeeId === null;
    }

    public function covers(PeriodTarget $target): bool
    {
        return $this->units->coversUnit($target->organization_unit_id)
            || ($this->ownEmployeeId !== null && $target->employee_id === $this->ownEmployeeId);
    }

    /**
     * @param  Builder<PeriodTarget>  $targets
     */
    public function apply(Builder $targets): void
    {
        if ($this->units->all) {
            return;
        }

        $targets->where(function (Builder $allowed): void {
            $allowed->whereIn('organization_unit_id', $this->units->unitIds === [] ? [0] : $this->units->unitIds);

            if ($this->ownEmployeeId !== null) {
                $allowed->orWhere('employee_id', $this->ownEmployeeId);
            }
        });
    }
}
