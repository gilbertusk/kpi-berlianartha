<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Models\User;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Application\ReportDefinition;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Application\ReportSupport;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\TeamReview\Application\TeamReviewAccess;
use App\Modules\TeamReview\Domain\ActionStatus;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CorrectiveAction;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Corrective actions in the `team_review.view` scope, with how many days an
 * outstanding action is past its due date. Overdue is a fact of the due
 * date the team set, not of an SLA.
 */
final class CorrectiveActionReport implements ReportDefinition
{
    public function __construct(
        private readonly ReportSupport $support,
        private readonly TeamReviewAccess $reviewAccess,
    ) {}

    public function key(): string
    {
        return 'corrective_actions';
    }

    public function title(): string
    {
        return 'Corrective action dan yang melewati tenggat';
    }

    public function description(): string
    {
        return 'Tindakan perbaikan per unit dengan PIC, tenggat, status, dan jumlah hari melewati tenggat.';
    }

    public function columns(): array
    {
        return [
            'Unit', 'Tanggal review', 'Masalah', 'Kode penyebab', 'KPI terdampak', 'Tindakan', 'PIC', 'Tenggat', 'Prioritas', 'Status',
            'Melewati tenggat', 'Hari melewati tenggat', 'Penyelesaian dilaporkan (UTC)', 'Diverifikasi oleh', 'Ditutup pada (UTC)',
        ];
    }

    public function filters(): array
    {
        return ['unit', 'status', 'from', 'to'];
    }

    public function statuses(): array
    {
        $statuses = ['overdue' => 'Melewati tenggat'];

        foreach (ActionStatus::cases() as $status) {
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

        if ($filters->status !== null && ! array_key_exists($filters->status, $this->statuses())) {
            throw new BusinessRuleViolation('invalid_filter', 'Status corrective action tidak dikenal.');
        }

        return new ReportContext($user, $filters, null, $this->support->units($filters, $scope), $this->support->describe($filters, $scope));
    }

    public function rows(ReportContext $context): iterable
    {
        $scope = $this->scope($context->user);
        $filters = $context->filters;
        $today = $this->support->access->businessToday();
        $outstanding = [ActionStatus::Open->value, ActionStatus::InProgress->value, ActionStatus::Waiting->value];

        $actions = CorrectiveAction::query()
            ->with(['issue.kpis', 'unit', 'owner', 'reviewer'])
            ->when(! $scope->all, fn ($query) => $query->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds))
            ->when($context->unitIds !== null, fn ($query) => $query->whereIn('organization_unit_id', $context->unitIds ?? []))
            ->when($filters->from !== null, fn ($query) => $query->where('due_on', '>=', $filters->from))
            ->when($filters->to !== null, fn ($query) => $query->where('due_on', '<=', $filters->to))
            ->when($filters->status === 'overdue', fn ($query) => $query->whereIn('status', $outstanding)->where('due_on', '<', $today))
            ->when($filters->status !== null && $filters->status !== 'overdue', fn ($query) => $query->where('status', $filters->status))
            ->orderBy('id')
            ->lazyById(200);

        foreach ($actions as $action) {
            $overdue = $action->isOverdueOn($today);

            yield [
                $action->unit->code, $action->issue->review_date, $action->issue->problem, $action->issue->cause_code,
                $action->issue->kpis->pluck('code')->implode(', '), $action->action, $action->owner->name, $action->due_on,
                $action->priority->label(), $action->status->label(), $overdue ? 'ya' : 'tidak',
                $overdue ? (string) (int) CarbonImmutable::parse($action->due_on)->diffInDays(CarbonImmutable::parse($today)) : null,
                $action->completed_at?->format('Y-m-d H:i:s'), $action->reviewer?->name, $action->closed_at?->format('Y-m-d H:i:s'),
            ];
        }
    }

    private function scope(User $user): UnitScope
    {
        return $this->reviewAccess->viewScope($user);
    }
}
