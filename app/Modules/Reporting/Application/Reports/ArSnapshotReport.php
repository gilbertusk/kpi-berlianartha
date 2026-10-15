<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Models\User;
use App\Modules\Accounting\Application\ArAccess;
use App\Modules\Accounting\Infrastructure\Persistence\Models\ArSnapshot;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Application\ReportDefinition;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Application\ReportSupport;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Base of reports over one committed receivables snapshot. Financial and
 * customer data: restricted, readable only in the `accounting_data.view`
 * scope of the snapshot's team.
 */
abstract class ArSnapshotReport implements ReportDefinition
{
    public function __construct(
        protected readonly ReportSupport $support,
        protected readonly ArAccess $access,
    ) {}

    public function statuses(): array
    {
        return [];
    }

    public function classification(): Classification
    {
        return Classification::Restricted;
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

        if ($filters->snapshot === null) {
            throw new BusinessRuleViolation('snapshot_required', 'Pilih snapshot piutang.');
        }

        $snapshot = $this->snapshot($filters);

        if ($snapshot === null || ! $this->access->canRead($user, $snapshot)) {
            throw new AuthorizationException;
        }

        return new ReportContext($user, $filters, null, [$snapshot->organization_unit_id], "{$snapshot->unit->code} — snapshot {$snapshot->source_system} per {$snapshot->as_of_date} v{$snapshot->version}");
    }

    protected function snapshot(ReportFilters $filters): ?ArSnapshot
    {
        return ArSnapshot::query()->with('unit')->where('public_id', (string) $filters->snapshot)->where('status', ArSnapshot::COMMITTED)->first();
    }

    protected function current(ReportContext $context): ArSnapshot
    {
        return $this->snapshot($context->filters) ?? throw new AuthorizationException;
    }
}
