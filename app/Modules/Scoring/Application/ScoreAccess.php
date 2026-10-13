<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\PeriodAssignment\Domain\SubjectType;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which scorecards an account may use: unit scorecards in one scope,
 * employee scorecards in another, plus its own scorecard. Viewing uses
 * `scorecard.view` for units and `individual_scorecard.view` for other
 * employees (ROLE_APPROVAL_MATRIX "Melihat score pribadi"); calculating
 * uses the `scorecard.calculate` scope for both.
 */
final readonly class ScoreAccess
{
    public function __construct(
        public UnitScope $units,
        public UnitScope $individuals,
        public ?int $ownEmployeeId,
    ) {}

    public static function forViewing(AccessControl $access, User $user): self
    {
        $units = $access->unitScope($user, Capability::ScorecardView);

        return new self($units, $access->unitScope($user, Capability::IndividualScorecardView), $units->self ? $user->employee?->id : null);
    }

    public static function forCalculating(AccessControl $access, User $user): self
    {
        $scope = $access->unitScope($user, Capability::ScorecardCalculate);

        return new self($scope, $scope, null);
    }

    public static function everything(): self
    {
        $all = new UnitScope(true, [], false);

        return new self($all, $all, null);
    }

    public function isEmpty(): bool
    {
        return ! $this->reachesOthers() && $this->ownEmployeeId === null;
    }

    /**
     * True when the account may see more than its own scorecard (and may
     * therefore export).
     */
    public function reachesOthers(): bool
    {
        return $this->units->reachesOthers() || $this->individuals->reachesOthers();
    }

    public function covers(PeriodAssignment $assignment): bool
    {
        if ($assignment->subject_type === SubjectType::Unit) {
            return $this->units->coversUnit($assignment->organization_unit_id);
        }

        return ($this->ownEmployeeId !== null && $assignment->employee_id === $this->ownEmployeeId)
            || $this->individuals->coversUnit($assignment->organization_unit_id);
    }

    /**
     * @param  Builder<PeriodAssignment>  $assignments
     */
    public function apply(Builder $assignments): void
    {
        if ($this->units->all && $this->individuals->all) {
            return;
        }

        $assignments->where(function (Builder $allowed): void {
            $allowed->where(function (Builder $units): void {
                $units->where('subject_type', SubjectType::Unit->value);
                self::restrict($units, $this->units);
            })->orWhere(function (Builder $employees): void {
                $employees->where('subject_type', SubjectType::Employee->value);
                self::restrict($employees, $this->individuals);
            });

            if ($this->ownEmployeeId !== null) {
                $allowed->orWhere('employee_id', $this->ownEmployeeId);
            }
        });
    }

    /**
     * @param  Builder<PeriodAssignment>  $query
     */
    private static function restrict(Builder $query, UnitScope $scope): void
    {
        if (! $scope->all) {
            $query->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds);
        }
    }
}
