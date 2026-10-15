<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Models\User;
use App\Modules\Production\Application\ProductionAccess;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Application\ReportDefinition;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Application\ReportSupport;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Shared\Application\Time\BusinessTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Base of reports over the current (latest committed) production batches in
 * the `production_data.view` scope, an optional team, and a date range.
 */
abstract class ProductionBatchReport implements ReportDefinition
{
    public function __construct(
        protected readonly ReportSupport $support,
        protected readonly ProductionAccess $access,
        protected readonly BusinessTime $time,
    ) {}

    public function classification(): Classification
    {
        return Classification::Internal;
    }

    public function available(User $user): bool
    {
        return $this->support->mayUseReports($user) && ! $this->access->readScope($user)->isEmpty();
    }

    public function authorize(User $user, ReportFilters $filters): ReportContext
    {
        if (! $this->available($user)) {
            throw new AuthorizationException;
        }

        $scope = $this->access->readScope($user);

        return new ReportContext($user, $filters, null, $this->support->units($filters, $scope), $this->support->describe($filters, $scope));
    }

    /**
     * @param  list<BatchKind>  $kinds
     * @return Builder<ProductionBatch>
     */
    protected function batches(ReportContext $context, array $kinds): Builder
    {
        $scope = $this->access->readScope($context->user)->unitIds;
        $units = $context->unitIds === null ? $scope : array_values(array_intersect($scope, $context->unitIds));
        $filters = $context->filters;

        return ProductionBatch::query()->current()
            ->with('unit')
            ->whereIn('kind', array_map(static fn (BatchKind $kind): string => $kind->value, $kinds))
            ->whereIn('organization_unit_id', $units === [] ? [0] : $units)
            ->when($filters->from !== null, fn (Builder $query) => $query->where('coverage_date', '>=', $filters->from))
            ->when($filters->to !== null, fn (Builder $query) => $query->where('coverage_date', '<=', $filters->to));
    }

    protected function local(?\DateTimeInterface $moment): ?string
    {
        return $moment === null ? null : $this->time->toBusiness($moment)->format('Y-m-d H:i');
    }
}
