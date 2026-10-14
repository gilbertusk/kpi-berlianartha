<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Models\User;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Application\ReportDefinition;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Application\ReportSupport;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Workflow\Application\WorkflowAccess;
use App\Modules\Workflow\Domain\CorrectionStatus;
use App\Modules\Workflow\Infrastructure\Persistence\Models\CorrectionRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Correction requests of a period with their decision and the actual
 * version they produced. Readable in the scope of the accounts that request
 * or approve corrections (HR, Management: they already see each request in
 * the correction queue) and of those that may read individual scorecards
 * (HR, Auditor); never about the reader's own results.
 */
final class CorrectionReport implements ReportDefinition
{
    public function __construct(
        private readonly ReportSupport $support,
        private readonly WorkflowAccess $workflowAccess,
    ) {}

    public function key(): string
    {
        return 'corrections';
    }

    public function title(): string
    {
        return 'Koreksi aktual';
    }

    public function description(): string
    {
        return 'Permintaan koreksi, nilai lama dan nilai yang diminta, alasan, peminta, pemutus, dan versi aktual yang dihasilkan.';
    }

    public function columns(): array
    {
        return [
            'Periode', 'Diminta pada (UTC)', 'Kode KPI', 'Kode subjek', 'Unit', 'Nilai berlaku', 'Versi berlaku', 'Nilai diminta', 'Alasan',
            'Referensi bukti', 'Status', 'Diminta oleh', 'Diputuskan oleh', 'Diputuskan pada (UTC)', 'Catatan keputusan', 'Versi baru',
        ];
    }

    public function filters(): array
    {
        return ['period', 'unit', 'kpi', 'status'];
    }

    public function statuses(): array
    {
        $statuses = [];

        foreach (CorrectionStatus::cases() as $status) {
            $statuses[$status->value] = $status->label();
        }

        return $statuses;
    }

    public function classification(): Classification
    {
        return Classification::Restricted;
    }

    public function available(User $user): bool
    {
        return $this->support->mayUseReports($user) && ! $this->scope($user)->isEmpty();
    }

    public function authorize(User $user, ReportFilters $filters): ReportContext
    {
        $this->support->assertMayUseReports($user);
        $scope = $this->scope($user);

        if ($scope->isEmpty()) {
            throw new AuthorizationException;
        }

        return new ReportContext($user, $filters, $this->support->period($filters), $this->support->units($filters, $scope), $this->support->describe($filters, $scope));
    }

    public function rows(ReportContext $context): iterable
    {
        $scope = $this->scope($context->user);
        $filters = $context->filters;
        $userId = $context->user->id;

        $requests = CorrectionRequest::query()
            ->with(['actual.target', 'currentVersion', 'newVersion', 'requestedBy', 'decidedBy'])
            ->where('assessment_period_id', $context->period?->id)
            ->when($filters->status !== null, fn (Builder $query) => $query->where('status', $filters->status))
            ->whereHas('actual.target', function (Builder $target) use ($scope, $context, $filters, $userId): void {
                if (! $scope->all) {
                    $target->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds);
                }

                if ($context->unitIds !== null) {
                    $target->whereIn('organization_unit_id', $context->unitIds);
                }

                if ($filters->kpi !== null) {
                    $target->where('kpi_code', $filters->kpi);
                }

                $target->where(fn (Builder $subject) => $subject
                    ->whereNull('employee_id')
                    ->orWhereDoesntHave('employee', fn (Builder $employee) => $employee->where('user_id', $userId)));
            })
            ->orderBy('id')
            ->lazyById(200);

        foreach ($requests as $request) {
            $target = $request->actual->target;

            yield [
                $context->period?->code, $request->requested_at->format('Y-m-d H:i:s'), $target->kpi_code, $target->subject_code, $target->unit_code,
                $request->currentVersion->displayValue(), (string) $request->currentVersion->version_number, $request->proposedDisplay(), $request->reason,
                $request->evidence_reference, $request->status->label(), $request->requestedBy->name, $request->decidedBy?->name,
                $request->decided_at?->format('Y-m-d H:i:s'), $request->decision_comment,
                $request->newVersion === null ? null : (string) $request->newVersion->version_number,
            ];
        }
    }

    private function scope(User $user): UnitScope
    {
        $corrections = $this->workflowAccess->correctionScope($user);
        $individuals = $this->support->access->unitScope($user, Capability::IndividualScorecardView);

        return new UnitScope($corrections->all || $individuals->all, array_values(array_unique([...$corrections->unitIds, ...$individuals->unitIds])), false);
    }
}
