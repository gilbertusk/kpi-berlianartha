<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\ActualEvidence\Application\ActualAccess;
use App\Modules\ActualEvidence\Application\ActualPeriodAccess;
use App\Modules\ActualEvidence\Application\ActualQuery;
use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Notification\Domain\DeliveryStatus;
use App\Modules\Notification\Infrastructure\Persistence\Models\NotificationDelivery;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Domain\SubjectType;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Scoring\Application\ScoreAccess;
use App\Modules\Scoring\Application\ScorecardQuery;
use App\Modules\Scoring\Application\ScorePeriodAccess;
use App\Modules\Scoring\Domain\ScorecardStatus;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculationLine;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use App\Modules\Workflow\Domain\CorrectionStatus;
use App\Modules\Workflow\Infrastructure\Persistence\Models\CorrectionRequest;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReviewDecision;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the role dashboards. Every figure is a count or a value of
 * stored records inside the account's scope, obtained by a bounded number
 * of queries, and each one names the page that lists those records.
 * Nothing is cached, so no figure can outlive a change of scope.
 */
final readonly class DashboardQuery
{
    private const PERIOD_LOOKBACK = 12;

    private const LIST_LIMIT = 20;

    public function __construct(
        private ScorecardQuery $scorecards,
        private ScorePeriodAccess $scoreAccess,
        private ActualQuery $actuals,
        private ActualPeriodAccess $actualAccess,
    ) {}

    /**
     * Periods past target setting that hold something the account may see,
     * newest first.
     *
     * @return Collection<int, AssessmentPeriod>
     */
    public function periods(User $user): Collection
    {
        return AssessmentPeriod::query()
            ->with('deadlines')
            ->whereIn('status', [PeriodStatus::InProgress->value, PeriodStatus::Review->value, PeriodStatus::Finalized->value, PeriodStatus::Locked->value])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->limit(self::PERIOD_LOOKBACK)
            ->get()
            ->filter(fn (AssessmentPeriod $period): bool => $this->scoreAccess->canView($user, $period)
                || $this->actualAccess->concerns($user, Capability::ActualView, $period))
            ->values();
    }

    /**
     * @return array{subjects: int, covered: int, not_calculated: int, complete: int, incomplete: int}
     */
    public function scorecardSummary(AssessmentPeriod $period, ScoreAccess $access): array
    {
        return $this->scorecards->summary($period, $access);
    }

    /**
     * Rating of the latest complete calculation per scorecard in scope
     * (the calculated rating, before any calibration).
     *
     * @return list<array{label: string, count: int}>
     */
    public function ratingDistribution(AssessmentPeriod $period, ScoreAccess $access): array
    {
        $rows = $this->latestCalculations($period, $access)
            ->where('scorecard_calculations.status', ScorecardStatus::Complete->value)
            ->toBase()
            ->selectRaw('rating_key, rating_label, COUNT(*) AS total')
            ->groupBy('rating_key', 'rating_label')
            ->orderBy('rating_key')
            ->get();

        return array_values($rows->map(static fn (object $row): array => ['label' => (string) $row->rating_label, 'count' => (int) $row->total])->all());
    }

    /**
     * KPI lines of the latest calculations whose score is below the approved
     * threshold. Without an approved threshold there is no "red" KPI.
     *
     * @return Collection<int, ScorecardCalculationLine>
     */
    public function kpisBelow(AssessmentPeriod $period, ScoreAccess $access, string $threshold): Collection
    {
        return ScorecardCalculationLine::query()
            ->with('calculation.assignment')
            ->whereIn('scorecard_calculation_id', $this->latestCalculations($period, $access)->select('scorecard_calculations.id'))
            ->whereNotNull('kpi_score')
            ->where('kpi_score', '<', $threshold)
            ->orderBy('kpi_score')
            ->orderBy('id')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * Unit scorecards in scope (units with a template, at most 20) with this period's final score and the final
     * score of the same unit in the previous period of the same type.
     *
     * @return list<array{assignment: PeriodAssignment, current: string|null, previous: string|null, previous_period: string|null, change: string|null}>
     */
    public function unitTrend(AssessmentPeriod $period, ScoreAccess $access): array
    {
        $units = $this->scorecards->base($period, $access)
            ->where('subject_type', SubjectType::Unit->value)
            ->where('coverage', Coverage::Covered->value)
            ->orderBy('unit_code')
            ->limit(self::LIST_LIMIT)
            ->get();

        if ($units->isEmpty()) {
            return [];
        }

        $previousPeriod = AssessmentPeriod::query()
            ->where('period_type', $period->period_type->value)
            ->where('organization_unit_id', $period->organization_unit_id)
            ->where('ends_on', '<', $period->starts_on)
            ->whereIn('status', [PeriodStatus::Review->value, PeriodStatus::Finalized->value, PeriodStatus::Locked->value])
            ->orderByDesc('ends_on')
            ->first();

        $current = $this->latestScores(array_values($units->map(static fn (PeriodAssignment $unit): int => $unit->id)->all()));
        $previous = [];

        if ($previousPeriod !== null) {
            $earlier = PeriodAssignment::query()
                ->where('assessment_period_id', $previousPeriod->id)
                ->where('subject_type', SubjectType::Unit->value)
                ->whereIn('subject_key', $units->pluck('subject_key')->all())
                ->pluck('id', 'subject_key');
            $scores = $this->latestScores(array_values($earlier->map(static fn ($id): int => (int) $id)->all()));

            foreach ($earlier as $subjectKey => $assignmentId) {
                $previous[(string) $subjectKey] = $scores[(int) $assignmentId] ?? null;
            }
        }

        $trend = [];

        foreach ($units as $unit) {
            $now = $current[$unit->id] ?? null;
            $before = $previous[$unit->subject_key] ?? null;
            $change = null;

            if ($now !== null && $before !== null) {
                $delta = BigDecimal::of($now)->minus($before);
                $amount = (string) $delta->abs()->toScale(4);
                $change = match ($delta->getSign()) {
                    1 => "Naik {$amount}",
                    -1 => "Turun {$amount}",
                    default => 'Tetap',
                };
            }

            $trend[] = [
                'assignment' => $unit,
                'current' => $now,
                'previous' => $before,
                'previous_period' => $previousPeriod?->code,
                'change' => $change,
            ];
        }

        return $trend;
    }

    /**
     * @return array{targets: int, missing: int, draft: int, submitted: int, rejected: int, verified: int, late: int, entries: int}
     */
    public function completeness(AssessmentPeriod $period, ActualAccess $access): array
    {
        return $this->actuals->completeness($period, $access);
    }

    /**
     * The employee's own KPI targets of the period with the state of their
     * actuals and any rejection feedback.
     *
     * @return list<array{target: PeriodTarget, state: string, feedback: string|null}>
     */
    public function ownKpis(AssessmentPeriod $period, int $employeeId): array
    {
        $targets = PeriodTarget::query()
            ->where('assessment_period_id', $period->id)
            ->where('employee_id', $employeeId)
            ->orderBy('kpi_code')
            ->limit(50)
            ->get();

        $versions = KpiActualVersion::query()
            ->join('kpi_actuals', 'kpi_actuals.id', '=', 'kpi_actual_versions.kpi_actual_id')
            ->whereIn('kpi_actuals.period_target_id', $targets->modelKeys())
            ->orderBy('kpi_actual_versions.id')
            ->get(['kpi_actuals.period_target_id', 'kpi_actual_versions.status', 'kpi_actual_versions.reject_reason']);

        $states = [];
        $feedback = [];

        foreach ($versions as $version) {
            $targetId = (int) $version->getAttribute('period_target_id');
            $states[$targetId][$version->status->value] = true;

            if ($version->status === ActualStatus::Rejected && $version->reject_reason !== null) {
                $feedback[$targetId] = $version->reject_reason;
            }
        }

        $rows = [];

        foreach ($targets as $target) {
            $state = 'missing';

            foreach ([ActualStatus::Rejected, ActualStatus::Draft, ActualStatus::Submitted, ActualStatus::Verified] as $status) {
                if (isset($states[$target->id][$status->value])) {
                    $state = $status->value;

                    break;
                }
            }

            $rows[] = ['target' => $target, 'state' => $state, 'feedback' => $feedback[$target->id] ?? null];
        }

        return $rows;
    }

    public function ownAssignment(AssessmentPeriod $period, int $employeeId): ?PeriodAssignment
    {
        return PeriodAssignment::query()->where('assessment_period_id', $period->id)->where('employee_id', $employeeId)->first();
    }

    public function latestCalculation(PeriodAssignment $assignment): ?ScorecardCalculation
    {
        return $this->scorecards->calculation($assignment);
    }

    /**
     * Read-only reconciliation counts for auditors.
     *
     * @return array{calculations: int, recalculations: int, decisions: int, calibrations_adjusted: int, corrections: int, reviews_final: int}
     */
    public function auditSummary(AssessmentPeriod $period): array
    {
        $reviews = ScorecardReview::query()->where('assessment_period_id', $period->id)->select('id');

        return [
            'calculations' => ScorecardCalculation::query()->where('assessment_period_id', $period->id)->count(),
            'recalculations' => ScorecardCalculation::query()->where('assessment_period_id', $period->id)->where('sequence_number', '>', 1)->count(),
            'decisions' => ScorecardReviewDecision::query()->whereIn('scorecard_review_id', $reviews)->count(),
            'calibrations_adjusted' => DB::table('scorecard_calibrations')->whereIn('scorecard_review_id', $reviews)->where('is_adjusted', true)->count(),
            'corrections' => CorrectionRequest::query()->where('assessment_period_id', $period->id)->where('status', CorrectionStatus::Approved->value)->count(),
            'reviews_final' => ScorecardReview::query()->where('assessment_period_id', $period->id)->where('state', 'final_approved')->count(),
        ];
    }

    /**
     * Technical health for system administrators. No KPI value appears here.
     *
     * @return array{outbox_pending: int, outbox_failed: int, failed_jobs: int, email_failed: int, email_pending: int}
     */
    public function health(): array
    {
        return [
            'outbox_pending' => OutboxMessage::query()->whereNull('processed_at')->whereNull('failed_at')->count(),
            'outbox_failed' => OutboxMessage::query()->whereNotNull('failed_at')->whereNull('processed_at')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'email_failed' => NotificationDelivery::query()->where('status', DeliveryStatus::Failed->value)->count(),
            'email_pending' => NotificationDelivery::query()->where('status', DeliveryStatus::Pending->value)->count(),
        ];
    }

    /**
     * Latest calculation per scorecard in scope.
     *
     * @return Builder<ScorecardCalculation>
     */
    private function latestCalculations(AssessmentPeriod $period, ScoreAccess $access): Builder
    {
        return ScorecardCalculation::query()
            ->whereIn('scorecard_calculations.period_assignment_id', $this->scorecards->base($period, $access)->select('period_assignments.id'))
            ->whereNotExists(fn (QueryBuilder $newer) => $newer
                ->from('scorecard_calculations as newer')
                ->whereColumn('newer.period_assignment_id', 'scorecard_calculations.period_assignment_id')
                ->whereColumn('newer.sequence_number', '>', 'scorecard_calculations.sequence_number'));
    }

    /**
     * @param  list<int>  $assignmentIds
     * @return array<int, string|null> assignment id => final score of its latest calculation
     */
    private function latestScores(array $assignmentIds): array
    {
        if ($assignmentIds === []) {
            return [];
        }

        $scores = [];

        $rows = ScorecardCalculation::query()
            ->whereIn('period_assignment_id', $assignmentIds)
            ->orderBy('sequence_number')
            ->get(['period_assignment_id', 'final_score']);

        foreach ($rows as $row) {
            $scores[$row->period_assignment_id] = $row->final_score;
        }

        return $scores;
    }
}
