<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\AccountStatus;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model for periodic access recertification (PRD §17 risk "akses
 * berlebihan"). Segregation-of-duties conflicts are flagged, not blocked:
 * the combinations are allowed by policy until the role matrix is signed off,
 * but they must be visible to reviewers.
 */
final readonly class AccessReviewQuery
{
    public const FLAG_ADMIN_WITH_BUSINESS_ROLE = 'admin_with_business_role';

    public const FLAG_AUDITOR_WITH_OPERATIONAL_ROLE = 'auditor_with_operational_role';

    public const FLAG_INACTIVE_WITH_GRANTS = 'inactive_with_grants';

    private const PER_PAGE = 20;

    private const BUSINESS_ROLES = [Role::Management, Role::DepartmentHead, Role::TeamLeader, Role::HrPerformance, Role::Auditor];

    private const OPERATIONAL_ROLES = [Role::Management, Role::DepartmentHead, Role::TeamLeader, Role::HrPerformance, Role::SystemAdmin];

    public const AUDIT_PREFIXES = ['auth.', 'account.', 'role.', 'delegation.', 'organization.', 'employee.'];

    public function __construct(private AccessControl $access) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function users(UnitScope $scope, string $search, ?Role $role, ?string $flag, int $page): LengthAwarePaginator
    {
        $today = $this->access->businessToday();
        $now = CarbonImmutable::now();

        $query = User::query()->with('employee');

        if (! $scope->all) {
            $unitIds = $scope->unitIds === [] ? [0] : $scope->unitIds;

            $query->where(fn (Builder $visible) => $visible
                ->whereHas('employee.assignments', function (Builder $assignments) use ($unitIds, $today): void {
                    $assignments->whereIn('organization_unit_id', $unitIds);
                    OrganizationDirectory::activeOn($assignments, $today);
                })
                ->orWhereHas('roleAssignments', fn (Builder $assignments) => $assignments
                    ->whereIn('organization_unit_id', $unitIds)
                    ->activeAt($today, $now)));
        }

        $needle = trim($search);

        if ($needle !== '') {
            $pattern = '%'.addcslashes(mb_strtolower($needle), '%_\\').'%';
            $query->where(fn (Builder $match) => $match
                ->whereRaw('lower(name) like ?', [$pattern])
                ->orWhereRaw('lower(email) like ?', [$pattern]));
        }

        if ($role !== null) {
            $query->whereHas('roleAssignments', fn (Builder $assignments) => $assignments->where('role', $role->value)->activeAt($today, $now));
        }

        match ($flag) {
            self::FLAG_ADMIN_WITH_BUSINESS_ROLE => $this->withBoth($query, [Role::SystemAdmin], self::BUSINESS_ROLES, $today, $now),
            self::FLAG_AUDITOR_WITH_OPERATIONAL_ROLE => $this->withBoth($query, [Role::Auditor], self::OPERATIONAL_ROLES, $today, $now),
            self::FLAG_INACTIVE_WITH_GRANTS => $query
                ->where('status', AccountStatus::Deactivated->value)
                ->whereHas('roleAssignments', fn (Builder $assignments) => $this->notEnded($assignments, $today, $now)),
            default => null,
        };

        return $query->orderBy('name')->orderBy('id')->paginate(self::PER_PAGE, ['*'], 'page', $page);
    }

    /**
     * Grants of an account exactly as authorization evaluates them now,
     * limited to what a reviewer with the given scope may see. Grants outside
     * a non-company reviewer's scope are only counted.
     *
     * @return array{visible: list<Grant>, hidden: int}
     */
    public function grantsOf(User $user, UnitScope $reviewerScope): array
    {
        $grants = $this->access->grants($user)->grants;

        if ($reviewerScope->all) {
            return ['visible' => $grants, 'hidden' => 0];
        }

        $visible = array_values(array_filter(
            $grants,
            fn (Grant $grant): bool => ! $grant->companyWide && $grant->unitId !== null && $reviewerScope->coversUnit($grant->unitId),
        ));

        return ['visible' => $visible, 'hidden' => count($grants) - count($visible)];
    }

    /**
     * @return list<string>
     */
    public function flagsOf(User $user): array
    {
        $today = $this->access->businessToday();
        $now = CarbonImmutable::now();

        $roles = $user->roleAssignments()
            ->where(fn (Builder $assignments) => $this->notEnded($assignments, $today, $now))
            ->get()
            ->filter(fn ($assignment): bool => $assignment->isActiveAt($today, $now) || ! $user->isActive())
            ->map(fn ($assignment): Role => $assignment->role)
            ->unique()
            ->values()
            ->all();

        $flags = [];

        if (in_array(Role::SystemAdmin, $roles, true) && array_intersect_key(self::roleKeys($roles), self::roleKeys(self::BUSINESS_ROLES)) !== []) {
            $flags[] = self::FLAG_ADMIN_WITH_BUSINESS_ROLE;
        }

        if (in_array(Role::Auditor, $roles, true) && array_intersect_key(self::roleKeys($roles), self::roleKeys(self::OPERATIONAL_ROLES)) !== []) {
            $flags[] = self::FLAG_AUDITOR_WITH_OPERATIONAL_ROLE;
        }

        if (! $user->isActive() && $roles !== []) {
            $flags[] = self::FLAG_INACTIVE_WITH_GRANTS;
        }

        return $flags;
    }

    /**
     * Identity and organization audit trail, newest first.
     *
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function auditTrail(string $prefix, int $page): LengthAwarePaginator
    {
        $prefixes = in_array($prefix, self::AUDIT_PREFIXES, true) ? [$prefix] : self::AUDIT_PREFIXES;

        return AuditLog::query()
            ->where(function (Builder $query) use ($prefixes): void {
                foreach ($prefixes as $value) {
                    $query->orWhere('action', 'like', $value.'%');
                }
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'auditPage', $page);
    }

    /**
     * @param  Builder<User>  $query
     * @param  list<Role>  $first
     * @param  list<Role>  $second
     */
    private function withBoth(Builder $query, array $first, array $second, string $today, CarbonImmutable $now): void
    {
        foreach ([$first, $second] as $roles) {
            $query->whereHas('roleAssignments', fn (Builder $assignments) => $assignments
                ->whereIn('role', array_map(fn (Role $role): string => $role->value, $roles))
                ->activeAt($today, $now));
        }
    }

    /**
     * Not revoked and not past its end date.
     *
     * @param  Builder<RoleAssignment>  $assignments
     */
    private function notEnded(Builder $assignments, string $today, CarbonImmutable $now): void
    {
        $assignments
            ->where(fn (Builder $revocation) => $revocation->whereNull('revoked_at')->orWhere('revoked_at', '>', $now))
            ->where(fn (Builder $period) => $period->whereNull('effective_until')->orWhere('effective_until', '>', $today));
    }

    /**
     * @param  array<int, Role>  $roles
     * @return array<string, true>
     */
    private static function roleKeys(array $roles): array
    {
        return array_fill_keys(array_map(fn (Role $role): string => $role->value, $roles), true);
    }
}
