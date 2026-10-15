<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Models\User;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\Operations\Application\OperationalAccess;
use App\Modules\Operations\Application\RecordTypeRegistry;
use App\Modules\Operations\Domain\RecordType;
use App\Modules\Operations\Infrastructure\Persistence\Models\OperationalRecord;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Application\ReportDefinition;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Application\ReportSupport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Base of reports over one operational record type: scope of that type,
 * optional unit and date range.
 */
abstract class OperationalRecordReport implements ReportDefinition
{
    public function __construct(
        protected readonly ReportSupport $support,
        protected readonly OperationalAccess $access,
        protected readonly RecordTypeRegistry $types,
    ) {}

    abstract protected function typeKey(): string;

    public function statuses(): array
    {
        return [];
    }

    public function available(User $user): bool
    {
        return $this->support->mayUseReports($user) && ! $this->scope($user)->isEmpty();
    }

    public function authorize(User $user, ReportFilters $filters): ReportContext
    {
        if (! $this->available($user)) {
            throw new AuthorizationException;
        }

        $scope = $this->scope($user);

        return new ReportContext($user, $filters, null, $this->support->units($filters, $scope), $this->support->describe($filters, $scope));
    }

    protected function type(): RecordType
    {
        return $this->types->get($this->typeKey());
    }

    protected function scope(User $user): UnitScope
    {
        return $this->access->readScope($user, $this->type());
    }

    /**
     * Live records of the type inside scope, unit, and date range.
     *
     * @return Builder<OperationalRecord>
     */
    protected function records(ReportContext $context): Builder
    {
        $scope = $this->scope($context->user);
        $filters = $context->filters;

        return OperationalRecord::query()
            ->where('record_type', $this->typeKey())
            ->whereNull('voided_at')
            ->when(! $scope->all, fn (Builder $query) => $query->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds))
            ->when($context->unitIds !== null, fn (Builder $query) => $query->whereIn('organization_unit_id', $context->unitIds ?? []))
            ->when($filters->from !== null, fn (Builder $query) => $query->where('occurred_on', '>=', $filters->from))
            ->when($filters->to !== null, fn (Builder $query) => $query->where('occurred_on', '<=', $filters->to));
    }
}
