<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\Operations\Domain\RecordType;
use App\Modules\Operations\Infrastructure\Persistence\Models\OperationalRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lists operational records inside the reader's scope for their type.
 */
final readonly class OperationalQuery
{
    public function __construct(private OperationalAccess $access) {}

    /**
     * @return Builder<OperationalRecord>
     */
    public function visible(User $user, RecordType $type): Builder
    {
        $scope = $this->access->readScope($user, $type);
        $query = OperationalRecord::query()->where('record_type', $type->key());

        self::restrict($query, $scope);

        return $query;
    }

    /**
     * @param  list<int>|null  $unitIds
     * @return LengthAwarePaginator<int, OperationalRecord>
     */
    public function page(User $user, RecordType $type, ?array $unitIds, bool $includeVoided, int $page): LengthAwarePaginator
    {
        return $this->visible($user, $type)
            ->with(['unit', 'recordedBy', 'kpiDefinition'])
            ->when($unitIds !== null, fn (Builder $query) => $query->whereIn('organization_unit_id', $unitIds ?? []))
            ->when(! $includeVoided, fn (Builder $query) => $query->whereNull('voided_at'))
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'halaman', $page);
    }

    /**
     * @param  Builder<OperationalRecord>  $query
     */
    public static function restrict(Builder $query, UnitScope $scope): void
    {
        if (! $scope->all) {
            $query->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds);
        }
    }
}
