<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\AssignmentType;
use App\Modules\Organization\Domain\UnitType;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Public read contract of the Organization module. Other modules ask it for
 * the hierarchy and an employee's units on a business date instead of
 * querying organization tables directly (DOMAIN_BOUNDARIES.md §3).
 */
final class OrganizationDirectory
{
    /** @var array<string, OrganizationSnapshot> */
    private array $snapshots = [];

    public function snapshot(string $businessDate): OrganizationSnapshot
    {
        return $this->snapshots[$businessDate] ??= $this->loadSnapshot($businessDate);
    }

    public function flush(): void
    {
        $this->snapshots = [];
    }

    /**
     * Units an employee belongs to on the date: the home team plus any
     * temporary assignment.
     *
     * @return list<int>
     */
    public function employeeUnitIds(int $employeeId, string $businessDate): array
    {
        $unitIds = DB::table('employee_assignments')
            ->where('employee_id', $employeeId)
            ->where(fn (Builder $query) => self::activeOn($query, $businessDate))
            ->orderBy('organization_unit_id')
            ->pluck('organization_unit_id')
            ->all();

        return array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $unitIds)));
    }

    public function homeUnitId(int $employeeId, string $businessDate): ?int
    {
        $unitId = DB::table('employee_assignments')
            ->where('employee_id', $employeeId)
            ->where('assignment_type', AssignmentType::Home->value)
            ->where(fn (Builder $query) => self::activeOn($query, $businessDate))
            ->value('organization_unit_id');

        return $unitId === null ? null : (int) $unitId;
    }

    /**
     * Every employee whose home team is one of the units on the date, ordered
     * by employee number. Used to snapshot who belongs to a period.
     *
     * @param  list<int>  $unitIds
     * @return list<HomeAssignment>
     */
    public function homeAssignmentsOn(array $unitIds, string $businessDate): array
    {
        if ($unitIds === []) {
            return [];
        }

        $rows = DB::table('employee_assignments as a')
            ->join('employees as e', 'e.id', '=', 'a.employee_id')
            ->where('a.assignment_type', AssignmentType::Home->value)
            ->whereIn('a.organization_unit_id', $unitIds)
            ->where(fn (Builder $query) => self::activeOn($query, $businessDate, 'a.'))
            ->orderBy('e.employee_number')
            ->orderBy('a.id')
            ->get(['a.id as assignment_id', 'a.organization_unit_id', 'a.position_title', 'e.id as employee_id', 'e.employee_number', 'e.full_name', 'e.user_id']);

        $assignments = [];

        foreach ($rows as $row) {
            $assignments[] = new HomeAssignment(
                assignmentId: (int) $row->assignment_id,
                employeeId: (int) $row->employee_id,
                employeeNumber: (string) $row->employee_number,
                fullName: (string) $row->full_name,
                userId: $row->user_id === null ? null : (int) $row->user_id,
                unitId: (int) $row->organization_unit_id,
                positionTitle: $row->position_title === null ? null : (string) $row->position_title,
            );
        }

        return $assignments;
    }

    /**
     * Applies the half-open effective period filter to a query whose
     * table has effective_from/effective_until columns.
     *
     * @param  Builder|EloquentBuilder<*>|Relation<*, *, *>  $query
     */
    public static function activeOn(Builder|EloquentBuilder|Relation $query, string $businessDate, string $prefix = ''): void
    {
        $query->where($prefix.'effective_from', '<=', $businessDate)
            ->where(fn ($period) => $period
                ->whereNull($prefix.'effective_until')
                ->orWhere($prefix.'effective_until', '>', $businessDate));
    }

    private function loadSnapshot(string $businessDate): OrganizationSnapshot
    {
        $rows = DB::table('organization_units as u')
            ->join('organization_unit_versions as v', 'v.organization_unit_id', '=', 'u.id')
            ->where(fn (Builder $query) => self::activeOn($query, $businessDate, 'u.'))
            ->where(fn (Builder $query) => self::activeOn($query, $businessDate, 'v.'))
            ->get(['u.id', 'u.public_id', 'u.code', 'u.unit_type', 'v.name', 'v.parent_unit_id']);

        $units = [];

        foreach ($rows as $row) {
            $units[(int) $row->id] = new UnitNode(
                id: (int) $row->id,
                publicId: (string) $row->public_id,
                code: (string) $row->code,
                type: UnitType::from((string) $row->unit_type),
                name: (string) $row->name,
                parentId: $row->parent_unit_id === null ? null : (int) $row->parent_unit_id,
            );
        }

        return new OrganizationSnapshot($businessDate, $units);
    }
}
