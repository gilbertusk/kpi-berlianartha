<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scoped employee directory. The caller passes the viewer's resolved scope;
 * filtering happens in SQL so pagination never leaks out-of-scope rows.
 */
final class EmployeeQuery
{
    private const PER_PAGE = 25;

    /**
     * @param  list<int>  $scopeUnitIds  units the viewer may see (ignored when $allUnits)
     * @return LengthAwarePaginator<int, Employee>
     */
    public function paginate(
        bool $allUnits,
        array $scopeUnitIds,
        ?int $selfUserId,
        string $asOf,
        ?int $unitFilter,
        string $search,
        int $page,
    ): LengthAwarePaginator {
        $query = Employee::query()
            ->with([
                'user',
                'assignments' => fn ($assignments) => OrganizationDirectory::activeOn($assignments, $asOf),
            ]);

        if (! $allUnits) {
            $query->where(function (Builder $visible) use ($scopeUnitIds, $selfUserId, $asOf): void {
                $visible->whereHas('assignments', function (Builder $assignments) use ($scopeUnitIds, $asOf): void {
                    $assignments->whereIn('organization_unit_id', $scopeUnitIds === [] ? [0] : $scopeUnitIds);
                    OrganizationDirectory::activeOn($assignments, $asOf);
                });

                if ($selfUserId !== null) {
                    $visible->orWhere('user_id', $selfUserId);
                }
            });
        }

        if ($unitFilter !== null) {
            $query->whereHas('assignments', function (Builder $assignments) use ($unitFilter, $asOf): void {
                $assignments->where('organization_unit_id', $unitFilter);
                OrganizationDirectory::activeOn($assignments, $asOf);
            });
        }

        $needle = trim($search);

        if ($needle !== '') {
            $pattern = '%'.addcslashes(mb_strtolower($needle), '%_\\').'%';

            $query->where(fn (Builder $match) => $match
                ->whereRaw('lower(full_name) like ?', [$pattern])
                ->orWhereRaw('lower(employee_number) like ?', [$pattern]));
        }

        return $query->orderBy('full_name')->orderBy('id')->paginate(self::PER_PAGE, ['*'], 'page', $page);
    }
}
