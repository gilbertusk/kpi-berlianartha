<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application;

use App\Models\User;
use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignmentApprover;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculationLine;
use App\Modules\Workflow\Domain\CorrectionStatus;
use App\Modules\Workflow\Domain\ReviewState;
use App\Modules\Workflow\Infrastructure\Persistence\Models\CorrectionRequest;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read side of the approval workflow. Every list is filtered by what the
 * account may see or decide; nothing is authorized in the views.
 */
final readonly class WorkflowQuery
{
    private const CORRECTION_LIMIT = 200;

    private const RELATIONS = ['assignment.approvers', 'assignment.employee', 'calculation', 'calibrations', 'period'];

    public function __construct(
        private WorkflowAccess $access,
        private ApprovalAuthority $authority,
        private WorkflowService $workflow,
        private PeriodService $periods,
    ) {}

    /**
     * Scorecards waiting for a decision this account may take now.
     *
     * @return Collection<int, ReviewRow>
     */
    public function inbox(User $user): Collection
    {
        return $this->rows(
            ScorecardReview::query()
                ->whereIn('state', [ReviewState::InReview->value, ReviewState::Reviewed->value, ReviewState::Calibrated->value])
                ->whereHas('period', fn (Builder $period) => $period->where('status', PeriodStatus::Review->value)),
            $user,
        )->filter(static fn (ReviewRow $row): bool => $row->actionable !== null)->values();
    }

    /**
     * Scorecards this account may send back into review: returned ones, and
     * ones recalculated after they entered review.
     *
     * @return Collection<int, ReviewRow>
     */
    public function toResubmit(User $user): Collection
    {
        return $this->rows(
            ScorecardReview::query()->whereHas('period', fn (Builder $period) => $period->where('status', PeriodStatus::Review->value)),
            $user,
        )->filter(fn (ReviewRow $row): bool => ($row->stale || $row->review->state === ReviewState::Returned)
            && $this->access->mayResubmit($user, $row->review->assignment))->values();
    }

    /**
     * Every review of the period this account may see.
     *
     * @return Collection<int, ReviewRow>
     */
    public function board(AssessmentPeriod $period, User $user, ?string $state = null, string $search = ''): Collection
    {
        $scope = $this->access->boardScope($user);
        $needle = mb_strtolower(trim($search));

        $query = ScorecardReview::query()->where('assessment_period_id', $period->id);

        if ($state !== null) {
            $query->where('state', $state);
        }

        return $this->rows($query, $user)
            ->filter(fn (ReviewRow $row): bool => $this->access->covers($user, $row->review->assignment, $scope))
            ->filter(static fn (ReviewRow $row): bool => $needle === ''
                || str_contains(mb_strtolower($row->review->assignment->subject_code.' '.$row->review->assignment->subject_name.' '.$row->review->assignment->unit_code), $needle))
            ->values();
    }

    public function row(ScorecardReview $review, User $user): ReviewRow
    {
        $review->loadMissing(self::RELATIONS);

        return $this->toRow($review, $user, WorkflowService::latestCalculations($review->assessment_period_id));
    }

    /**
     * The approver chain of one scorecard: per stage, who was configured when
     * the period opened, any gap, and what happened in the current round.
     *
     * @return list<array{stage: ApprovalStage, approvers: list<string>, gap: string|null, bypass: string|null, decision: string|null}>
     */
    public function chain(ScorecardReview $review): array
    {
        $review->loadMissing([...self::RELATIONS, 'decisions.actor', 'decisions.actingFor']);
        $assignment = $review->assignment;
        $chain = [];

        foreach ([ApprovalStage::Review, ApprovalStage::Calibration, ApprovalStage::FinalApproval] as $stage) {
            $decision = $review->decisions->first(fn ($entry): bool => $entry->round === $review->round && $entry->stage === $stage->value);
            $gap = ($assignment->approver_gaps ?? [])[$stage->value] ?? null;
            $bypass = $stage === ApprovalStage::Review && $gap !== null ? $this->workflow->bypassFor($assignment) : null;

            $chain[] = [
                'stage' => $stage,
                'approvers' => array_values($assignment->approvers
                    ->filter(static fn (PeriodAssignmentApprover $approver): bool => $approver->stage === $stage)
                    ->map(static fn (PeriodAssignmentApprover $approver): string => "{$approver->user_name} ({$approver->scope_unit_code})")
                    ->all()),
                'gap' => $gap === null ? null : ApprovalStage::gapLabel($gap),
                'bypass' => $bypass?->signoff_reference,
                'decision' => $decision === null ? null : $decision->outcome->label().' — '.$decision->actor->name
                    .($decision->actingFor === null ? '' : ' (atas nama '.$decision->actingFor->name.')'),
            ];
        }

        return $chain;
    }

    /**
     * What stands between the period and its next status.
     *
     * @return array{next: PeriodStatus|null, blockers: list<string>}
     */
    public function checklist(AssessmentPeriod $period): array
    {
        $next = match ($period->status) {
            PeriodStatus::InProgress => PeriodStatus::Review,
            PeriodStatus::Review => PeriodStatus::Finalized,
            PeriodStatus::Finalized => PeriodStatus::Locked,
            default => null,
        };

        return ['next' => $next, 'blockers' => $next === null ? [] : $this->periods->blockersFor($period, $next)];
    }

    /**
     * @return array<string, int> state value => count, for the reviews the account may see
     */
    public function stateCounts(AssessmentPeriod $period, User $user): array
    {
        $counts = array_fill_keys(array_map(static fn (ReviewState $state): string => $state->value, ReviewState::cases()), 0);

        foreach ($this->board($period, $user) as $row) {
            $counts[$row->review->state->value]++;
        }

        return $counts;
    }

    /**
     * Correction requests on KPI units this account may request or approve
     * corrections for, never about the account's own results. The scope is
     * applied in the query, so the row limit cannot hide a request.
     *
     * @return Collection<int, CorrectionRequest>
     */
    public function corrections(User $user, ?AssessmentPeriod $period = null, bool $pendingOnly = false): Collection
    {
        $scope = $this->access->correctionScope($user);

        if ($scope->isEmpty()) {
            return new Collection;
        }

        return CorrectionRequest::query()
            ->with(['actual.target.employee', 'currentVersion', 'newVersion', 'requestedBy', 'decidedBy', 'period'])
            ->whereHas('actual.target', function (Builder $target) use ($scope, $user): void {
                if (! $scope->all) {
                    $target->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds);
                }

                $target->where(fn (Builder $subject) => $subject
                    ->whereNull('employee_id')
                    ->orWhereDoesntHave('employee', fn (Builder $employee) => $employee->where('user_id', $user->id)));
            })
            ->when($period !== null, fn (Builder $query) => $query->where('assessment_period_id', $period?->id))
            ->when($pendingOnly, fn (Builder $query) => $query->where('status', CorrectionStatus::Pending->value))
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [CorrectionStatus::Pending->value])
            ->orderByDesc('id')
            ->limit(self::CORRECTION_LIMIT)
            ->get()
            ->values();
    }

    /**
     * Pending requests this account may decide (not its own).
     *
     * @return Collection<int, CorrectionRequest>
     */
    public function correctionsToDecide(User $user): Collection
    {
        return $this->corrections($user, null, true)
            ->filter(fn (CorrectionRequest $request): bool => $request->requested_by_user_id !== $user->id
                && $this->access->mayApproveCorrection($user, $request->actual->target->organization_unit_id))
            ->values();
    }

    public function mayHandle(User $user, CorrectionRequest $request): bool
    {
        $target = $request->actual->target;

        if ($target->employee?->user_id === $user->id) {
            return false;
        }

        return $this->access->mayRequestCorrection($user, $target->organization_unit_id) || $this->access->mayApproveCorrection($user, $target->organization_unit_id);
    }

    /**
     * Verified actuals behind the calculation of one scorecard that this
     * account may ask to correct, keyed by the actual's public id.
     *
     * @return array<string, string>
     */
    public function correctableActuals(ScorecardReview $review, User $user): array
    {
        $targetIds = ScorecardCalculationLine::query()
            ->where('scorecard_calculation_id', $review->scorecard_calculation_id)
            ->whereNotNull('period_target_id')
            ->pluck('period_target_id');

        $options = [];

        $actuals = KpiActual::query()->with(['target', 'versions'])->whereIn('period_target_id', $targetIds)->orderBy('id')->get();

        foreach ($actuals as $actual) {
            /** @var KpiActualVersion|null $current */
            $current = $actual->versions->sortByDesc('version_number')->first();

            if ($current === null || $current->status !== ActualStatus::Verified || ! $this->access->mayRequestCorrection($user, $actual->target->organization_unit_id)) {
                continue;
            }

            $options[$actual->public_id] = "{$actual->target->kpi_code} · {$actual->target->subject_code} · {$actual->windowLabel()} · {$current->displayValue()} {$current->unit}";
        }

        return $options;
    }

    /**
     * @param  Builder<ScorecardReview>  $query
     * @return Collection<int, ReviewRow>
     */
    private function rows(Builder $query, User $user): Collection
    {
        $latest = [];

        return $query->with(self::RELATIONS)->orderBy('id')->get()->map(function (ScorecardReview $review) use ($user, &$latest): ReviewRow {
            $latest[$review->assessment_period_id] ??= WorkflowService::latestCalculations($review->assessment_period_id);

            return $this->toRow($review, $user, $latest[$review->assessment_period_id]);
        });
    }

    /**
     * @param  array<int, ScorecardCalculation>  $latest
     */
    private function toRow(ScorecardReview $review, User $user, array $latest): ReviewRow
    {
        $assignment = $review->assignment;
        $pending = $review->period->status === PeriodStatus::Review ? $review->pendingStage() : null;
        $actionable = null;
        $viaBypass = false;
        $waitingOn = null;

        if ($pending !== null && $this->authority->resolve($user, $assignment, $pending) !== null) {
            $actionable = $pending;
        }

        if ($pending !== null) {
            $gap = ($assignment->approver_gaps ?? [])[$pending->value] ?? null;

            if ($gap !== null) {
                $bypassed = $pending === ApprovalStage::Review && $this->workflow->bypassFor($assignment) !== null;
                $waitingOn = $bypassed ? null : $pending->label().': '.ApprovalStage::gapLabel($gap);

                if ($bypassed && $this->authority->resolve($user, $assignment, ApprovalStage::Calibration) !== null) {
                    $actionable = ApprovalStage::Calibration;
                    $viaBypass = true;
                }
            }
        }

        return new ReviewRow(
            review: $review,
            score: WorkflowService::effectiveScore($review, $review->calculation),
            actionable: $actionable,
            viaBypass: $viaBypass,
            waitingOn: $waitingOn,
            stale: ($latest[$review->period_assignment_id] ?? null)?->id !== $review->scorecard_calculation_id,
        );
    }
}
