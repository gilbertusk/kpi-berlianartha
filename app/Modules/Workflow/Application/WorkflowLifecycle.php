<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application;

use App\Modules\PeriodAssignment\Application\PeriodLifecycleParticipant;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use App\Modules\Workflow\Domain\CorrectionStatus;
use App\Modules\Workflow\Domain\ReviewState;
use App\Modules\Workflow\Infrastructure\Persistence\Models\CorrectionRequest;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;
use Illuminate\Database\Eloquent\Builder;

/**
 * The workflow's part in the period lifecycle: reviews are opened when the
 * period enters REVIEW, and the period is finalized or locked only when
 * every scorecard is final, on its latest calculation, with no correction
 * waiting and no workflow event stuck in the outbox.
 */
final readonly class WorkflowLifecycle implements PeriodLifecycleParticipant
{
    private const EVENTS = ['CalibrationCompleted', 'FinalApprovalCompleted', 'CorrectionRequested', 'CorrectionApproved', 'FinalScoreCorrected', 'PeriodLocked'];

    public function __construct(private WorkflowService $workflow) {}

    public function periodOpened(AssessmentPeriod $period, Actor $actor): void {}

    public function periodAdvanced(AssessmentPeriod $period, PeriodStatus $from, Actor $actor): void
    {
        if ($period->status === PeriodStatus::Review) {
            $this->workflow->openReviews($period, $actor);
        }
    }

    public function blockersFor(AssessmentPeriod $period, PeriodStatus $next): array
    {
        if ($next !== PeriodStatus::Finalized && $next !== PeriodStatus::Locked) {
            return [];
        }

        $blockers = [];
        $reviews = ScorecardReview::query()->where('assessment_period_id', $period->id)->get(['id', 'period_assignment_id', 'scorecard_calculation_id', 'state']);
        $covered = PeriodAssignment::query()->where('assessment_period_id', $period->id)->where('coverage', Coverage::Covered->value)->count();

        if ($reviews->count() < $covered) {
            $blockers[] = ($covered - $reviews->count()).' scorecard belum masuk alur approval.';
        }

        $open = $reviews->filter(static fn (ScorecardReview $review): bool => $review->state !== ReviewState::FinalApproved)->count();

        if ($open > 0) {
            $blockers[] = "{$open} scorecard belum mendapat approval final.";
        }

        $latest = WorkflowService::latestCalculations($period->id);
        $stale = $reviews->filter(static fn (ScorecardReview $review): bool => ($latest[$review->period_assignment_id] ?? null)?->id !== $review->scorecard_calculation_id)->count();

        if ($stale > 0) {
            $blockers[] = "{$stale} scorecard disetujui pada perhitungan yang bukan perhitungan terakhir.";
        }

        $pending = CorrectionRequest::query()->where('assessment_period_id', $period->id)->where('status', CorrectionStatus::Pending->value)->count();

        if ($pending > 0) {
            $blockers[] = "{$pending} permintaan koreksi masih menunggu keputusan.";
        }

        if ($next === PeriodStatus::Locked) {
            $failed = OutboxMessage::query()
                ->whereIn('event_type', self::EVENTS)
                ->whereNotNull('failed_at')
                ->whereNull('processed_at')
                ->where(fn (Builder $ours) => $ours->where('aggregate_id', $period->public_id)->orWhere('payload->period', $period->public_id))
                ->count();

            if ($failed > 0) {
                $blockers[] = "{$failed} event workflow gagal diproses di outbox dan harus dipulihkan lebih dulu.";
            }
        }

        return $blockers;
    }
}
