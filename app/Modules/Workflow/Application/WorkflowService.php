<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Scoring\Domain\ScorecardPolicy;
use App\Modules\Scoring\Domain\ScorecardStatus;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Outbox\OutboxEvent;
use App\Modules\Shared\Application\Outbox\OutboxPublisher;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\Exceptions\SafeDomainException;
use App\Modules\Workflow\Domain\DecisionOutcome;
use App\Modules\Workflow\Domain\ReviewState;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ApprovalStageBypass;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardCalibration;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReviewDecision;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use LogicException;

/**
 * Review, calibration, final approval, return, and resubmission of the
 * scorecards of a period in REVIEW. Every decision is taken on the locked
 * review row with the lock version the approver saw, so a double click or
 * two approvers acting at once produce exactly one decision.
 *
 * The calculated score is never changed here: calibration stores the
 * calibrated score next to the calculated one, and the rating of a
 * calibrated score comes from the policy stored with the calculation.
 */
final readonly class WorkflowService
{
    use GuardsInput;

    public const BULK_LIMIT = 200;

    private const SCORE = '/^-?\d{1,12}(?:\.\d{1,4})?$/';

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OutboxPublisher $outbox,
        private OptimisticLock $lock,
        private ApprovalAuthority $authority,
        private AccessControl $access,
    ) {}

    /**
     * Opens one review per covered scorecard when the period enters REVIEW.
     * Runs inside the transaction of the period transition.
     */
    public function openReviews(AssessmentPeriod $period, Actor $actor): int
    {
        $latest = self::latestCalculations($period->id);
        $opened = 0;

        $assignments = PeriodAssignment::query()
            ->where('assessment_period_id', $period->id)
            ->where('coverage', Coverage::Covered->value)
            ->orderBy('id')
            ->pluck('id');

        foreach ($assignments as $assignmentId) {
            $calculation = $latest[(int) $assignmentId] ?? null;

            if ($calculation === null || $calculation->status !== ScorecardStatus::Complete) {
                throw new BusinessRuleViolation('period_not_ready', 'Ada scorecard tanpa perhitungan lengkap; periode belum dapat masuk tahap Review.');
            }

            ScorecardReview::query()->create([
                'assessment_period_id' => $period->id,
                'period_assignment_id' => (int) $assignmentId,
                'scorecard_calculation_id' => $calculation->id,
                'state' => ReviewState::InReview,
            ]);
            $opened++;
        }

        $this->audit->record(new AuditEntry(
            action: 'workflow.reviews_opened',
            entityType: 'assessment_period',
            entityId: $period->public_id,
            before: null,
            after: ['reviews' => $opened],
            reason: null,
            context: $actor->audit,
        ));

        if ($opened > 0) {
            $this->stageReady($period, ApprovalStage::Review, null, 'opened');
        }

        return $opened;
    }

    /**
     * Approves the pending review stage or the final approval stage.
     */
    public function approve(Actor $actor, User $user, int $reviewId, int $expectedVersion, ?string $comment = null): ScorecardReview
    {
        self::assertSameAccount($actor, $user);
        $comment = self::optionalText($comment, 1000);

        return $this->transactions->run(function () use ($actor, $user, $reviewId, $expectedVersion, $comment): ScorecardReview {
            [$review, $assignment] = $this->open($reviewId);
            $stage = self::pending($review);

            if ($stage === ApprovalStage::Calibration) {
                throw new BusinessRuleViolation('calibration_required', 'Scorecard ini menunggu kalibrasi, bukan approval.');
            }

            $authority = $this->authorize($user, $assignment, $stage);
            $this->assertNotAlreadyActed($review, $user, $authority);
            $calculation = self::currentCalculation($review);

            $from = $review->state;
            $to = $stage === ApprovalStage::Review ? ReviewState::Reviewed : ReviewState::FinalApproved;
            $review = $this->lock->update($review, $expectedVersion, ['state' => $to->value]);
            $this->decide($review, $stage->value, DecisionOutcome::Approved, $user, $authority, $comment, null);
            $this->record($actor, $review, $stage === ApprovalStage::Review ? 'scorecard_review.reviewed' : 'scorecard_review.final_approved', $from, $authority, $comment);

            if ($to === ReviewState::Reviewed) {
                $this->stageReady($review->period, ApprovalStage::Calibration, $review, "round-{$review->round}");
            }

            if ($to === ReviewState::FinalApproved) {
                $score = self::effectiveScore($review->load('calibrations'), $calculation);
                $this->outbox->publish(new OutboxEvent(
                    eventType: 'FinalApprovalCompleted',
                    eventVersion: 1,
                    aggregateType: 'scorecard_review',
                    aggregateId: $review->public_id,
                    payload: [
                        'period' => $review->period->public_id,
                        'assignment' => $assignment->public_id,
                        'calculation' => $calculation->public_id,
                        'round' => $review->round,
                        'final_score' => $score['score'],
                        'rating' => $score['rating_key'],
                        'calibrated' => $score['adjusted'],
                    ],
                    deduplicationKey: "final-approval:{$review->public_id}:{$review->round}",
                ));
            }

            return $review;
        });
    }

    /**
     * Approves several scorecards, each in its own transaction, and reports
     * the outcome per scorecard: null = approved, otherwise the reason.
     *
     * @param  array<int, int>  $locks  review id => lock version the approver saw
     * @return array<int, string|null>
     */
    public function approveMany(Actor $actor, User $user, array $locks, ?string $comment = null): array
    {
        if ($locks === []) {
            throw new BusinessRuleViolation('nothing_selected', 'Pilih minimal satu scorecard.');
        }

        if (count($locks) > self::BULK_LIMIT) {
            throw new BusinessRuleViolation('bulk_limit', 'Maksimal '.self::BULK_LIMIT.' scorecard per aksi.');
        }

        $results = [];

        foreach ($locks as $reviewId => $expectedVersion) {
            try {
                $this->approve($actor, $user, $reviewId, $expectedVersion, $comment);
                $results[$reviewId] = null;
            } catch (SafeDomainException $exception) {
                $results[$reviewId] = $exception->safeMessage();
            } catch (AuthorizationException) {
                $results[$reviewId] = 'Anda tidak berwenang memutuskan tahap ini.';
            }
        }

        return $results;
    }

    /**
     * Calibrates a reviewed scorecard. A null score confirms the calculated
     * score; a different score needs a reason and must be a score the policy
     * of the calculation can rate. When the review stage has no approver and
     * a signed bypass for the team is in force, the bypass is recorded first.
     */
    public function calibrate(Actor $actor, User $user, int $reviewId, int $expectedVersion, ?string $calibratedScore, ?string $reason, ?string $evidenceReference = null): ScorecardReview
    {
        self::assertSameAccount($actor, $user);
        $reason = self::optionalText($reason, 2000);
        $evidenceReference = self::optionalText($evidenceReference, 255);

        return $this->transactions->run(function () use ($actor, $user, $reviewId, $expectedVersion, $calibratedScore, $reason, $evidenceReference): ScorecardReview {
            [$review, $assignment] = $this->open($reviewId);

            if (! in_array($review->state, [ReviewState::InReview, ReviewState::Reviewed], true)) {
                throw new BusinessRuleViolation('stage_not_pending', 'Scorecard ini tidak sedang menunggu kalibrasi.');
            }

            $authority = $this->authorize($user, $assignment, ApprovalStage::Calibration);
            $this->assertNotAlreadyActed($review, $user, $authority);
            $calculation = self::currentCalculation($review);

            if ($review->state === ReviewState::InReview) {
                $bypass = $this->bypassFor($assignment) ?? throw new BusinessRuleViolation('review_pending', 'Tahap review belum selesai. Kalibrasi tanpa review hanya dapat dilakukan bila tahap review tidak memiliki approver dan bypass untuk team ini sudah disetujui.');
                $review = $this->lock->update($review, $expectedVersion, ['state' => ReviewState::Reviewed->value]);
                $expectedVersion = $review->lock_version;
                $this->decide($review, ApprovalStage::Review->value, DecisionOutcome::Bypassed, $user, $authority, "Bypass {$bypass->signoff_reference}", $bypass->id);
            }

            $calibrated = $this->calibration($calculation, $calibratedScore, $reason);

            ScorecardCalibration::query()->create([
                'scorecard_review_id' => $review->id,
                'round' => $review->round,
                'scorecard_calculation_id' => $calculation->id,
                'original_final_score' => $calculation->final_score,
                'original_rating_key' => $calculation->rating_key,
                'original_rating_label' => $calculation->rating_label,
                'calibrated_final_score' => $calibrated['score'],
                'calibrated_rating_key' => $calibrated['rating_key'],
                'calibrated_rating_label' => $calibrated['rating_label'],
                'is_adjusted' => $calibrated['adjusted'],
                'reason' => $reason,
                'evidence_reference' => $evidenceReference,
                'calibrated_by_user_id' => $user->id,
                'calibrated_at' => CarbonImmutable::now(),
            ]);

            $from = $review->state;
            $review = $this->lock->update($review, $expectedVersion, ['state' => ReviewState::Calibrated->value]);
            $this->decide($review, ApprovalStage::Calibration->value, DecisionOutcome::Approved, $user, $authority, $reason, null);

            $this->audit->record(new AuditEntry(
                action: 'scorecard_review.calibrated',
                entityType: 'scorecard_review',
                entityId: $review->public_id,
                before: ['state' => $from->value, 'final_score' => $calculation->final_score, 'rating' => $calculation->rating_key],
                after: ['state' => $review->state->value, 'round' => $review->round, 'final_score' => $calibrated['score'], 'rating' => $calibrated['rating_key'], 'adjusted' => $calibrated['adjusted'], 'evidence_reference' => $evidenceReference, ...$authority->toAudit()],
                reason: $reason,
                context: $actor->audit,
            ));

            $this->outbox->publish(new OutboxEvent(
                eventType: 'CalibrationCompleted',
                eventVersion: 1,
                aggregateType: 'scorecard_review',
                aggregateId: $review->public_id,
                payload: [
                    'period' => $review->period->public_id,
                    'assignment' => $assignment->public_id,
                    'calculation' => $calculation->public_id,
                    'round' => $review->round,
                    'adjusted' => $calibrated['adjusted'],
                ],
                deduplicationKey: "calibration:{$review->public_id}:{$review->round}",
            ));
            $this->stageReady($review->period, ApprovalStage::FinalApproval, $review, "round-{$review->round}");

            return $review;
        });
    }

    /**
     * The approver of the pending stage sends the scorecard back with a
     * reason. Nothing is deleted; the round stays in the history.
     */
    public function returnForRevision(Actor $actor, User $user, int $reviewId, int $expectedVersion, string $comment): ScorecardReview
    {
        self::assertSameAccount($actor, $user);
        $comment = self::requireText($comment, 'comment', 'Alasan pengembalian wajib diisi.', 1000);

        return $this->transactions->run(function () use ($actor, $user, $reviewId, $expectedVersion, $comment): ScorecardReview {
            [$review, $assignment] = $this->open($reviewId);
            $stage = self::pending($review);
            $authority = $this->authorize($user, $assignment, $stage);

            $from = $review->state;
            $review = $this->lock->update($review, $expectedVersion, ['state' => ReviewState::Returned->value]);
            $this->decide($review, $stage->value, DecisionOutcome::Returned, $user, $authority, $comment, null);
            $this->record($actor, $review, 'scorecard_review.returned', $from, $authority, $comment);

            $this->outbox->publish(new OutboxEvent(
                eventType: 'ScorecardReturned',
                eventVersion: 1,
                aggregateType: 'scorecard_review',
                aggregateId: $review->public_id,
                payload: ['period' => $review->period->public_id, 'stage' => $stage->value, 'round' => $review->round],
                deduplicationKey: "scorecard-returned:{$review->public_id}:{$review->round}",
            ));

            return $review;
        });
    }

    /**
     * HR sends a scorecard back into review as a new round, on the latest
     * complete calculation: after it was returned, or when it was
     * recalculated after entering review (whatever stage it had reached,
     * since the approvals given were for another score).
     */
    public function resubmit(Actor $actor, User $user, int $reviewId, int $expectedVersion, string $comment): ScorecardReview
    {
        self::assertSameAccount($actor, $user);
        $comment = self::requireText($comment, 'comment', 'Catatan tindak lanjut wajib diisi.', 1000);

        return $this->transactions->run(function () use ($actor, $user, $reviewId, $expectedVersion, $comment): ScorecardReview {
            [$review, $assignment] = $this->open($reviewId);

            $calculation = self::latestCalculation($assignment->id);

            if ($review->state !== ReviewState::Returned && $calculation?->id === $review->scorecard_calculation_id) {
                throw new BusinessRuleViolation('stage_not_pending', 'Hanya scorecard yang dikembalikan atau yang memiliki perhitungan lebih baru yang dapat diajukan ulang.');
            }

            $decision = $this->access->decide($user, Capability::ScorecardCalculate, $this->access->unitTarget($assignment->organization_unit_id));

            if (! $decision->allowed || $decision->grant === null) {
                throw new AuthorizationException;
            }

            if ($assignment->employee?->user_id === $user->id) {
                throw new BusinessRuleViolation('self_action', 'Anda tidak dapat mengajukan ulang scorecard Anda sendiri.');
            }

            if ($calculation === null || $calculation->status !== ScorecardStatus::Complete) {
                throw new BusinessRuleViolation('scorecard_incomplete', 'Perhitungan terakhir scorecard ini belum lengkap, sehingga belum dapat diajukan ulang.');
            }

            $from = $review->state;
            $review = $this->lock->update($review, $expectedVersion, [
                'state' => ReviewState::InReview->value,
                'round' => $review->round + 1,
                'scorecard_calculation_id' => $calculation->id,
            ]);

            ScorecardReviewDecision::query()->create([
                'scorecard_review_id' => $review->id,
                'round' => $review->round,
                'stage' => DecisionOutcome::STAGE_RESUBMISSION,
                'outcome' => DecisionOutcome::Resubmitted,
                'actor_user_id' => $user->id,
                'actor_role' => $decision->grant->role->value,
                'grant_source' => $decision->grant->source,
                'grant_reference' => $decision->grant->sourcePublicId,
                'scorecard_calculation_id' => $calculation->id,
                'comment' => $comment,
                'decided_at' => CarbonImmutable::now(),
            ]);

            $this->audit->record(new AuditEntry(
                action: 'scorecard_review.resubmitted',
                entityType: 'scorecard_review',
                entityId: $review->public_id,
                before: ['state' => $from->value, 'round' => $review->round - 1],
                after: ['state' => $review->state->value, 'round' => $review->round, 'calculation' => $calculation->public_id],
                reason: $comment,
                context: $actor->audit,
            ));
            $this->stageReady($review->period, ApprovalStage::Review, $review, "round-{$review->round}");

            return $review;
        });
    }

    /**
     * Points every review of the period at the calculation an approved
     * correction produced. In REVIEW the scorecard is approved again from the
     * start; in a FINALIZED or LOCKED period the approved correction itself
     * is the authorization, and the review stays final. Runs inside the
     * transaction of the correction.
     *
     * @return int number of scorecards whose calculation changed
     */
    public function syncAfterCorrection(Actor $actor, AssessmentPeriod $period, string $correctionPublicId, string $reason, string $approverRole): int
    {
        $latest = self::latestCalculations($period->id);
        $changed = 0;

        $reviews = ScorecardReview::query()->with(['calibrations', 'calculation'])->where('assessment_period_id', $period->id)->orderBy('id')->lockForUpdate()->get();

        foreach ($reviews as $review) {
            $calculation = $latest[$review->period_assignment_id] ?? null;

            if ($calculation === null || $calculation->status !== ScorecardStatus::Complete) {
                throw new BusinessRuleViolation('correction_breaks_scorecard', 'Koreksi ini membuat sebuah scorecard tidak lagi dapat dihitung lengkap, sehingga tidak dapat diterapkan.');
            }

            if ($calculation->id === $review->scorecard_calculation_id) {
                continue;
            }

            $from = $review->state;
            $previous = self::effectiveScore($review, $review->calculation);
            $attributes = ['scorecard_calculation_id' => $calculation->id];

            // No rule says what becomes of a calibrated score when its basis
            // changes after final approval, so the system does not decide it.
            if ($period->status !== PeriodStatus::Review && $previous['adjusted']) {
                throw new BusinessRuleViolation('correction_needs_recalibration', 'Koreksi ini mengubah scorecard yang skornya disesuaikan pada kalibrasi dan sudah final. Aturan untuk kasus ini belum disetujui, sehingga koreksi tidak dapat diterapkan.');
            }

            if ($period->status === PeriodStatus::Review && $review->state !== ReviewState::InReview) {
                $attributes['state'] = ReviewState::InReview->value;
                $attributes['round'] = $review->round + 1;
            }

            $review = $this->lock->update($review, $review->lock_version, $attributes);

            ScorecardReviewDecision::query()->create([
                'scorecard_review_id' => $review->id,
                'round' => $review->round,
                'stage' => DecisionOutcome::STAGE_CORRECTION,
                'outcome' => DecisionOutcome::Recalculated,
                'actor_user_id' => $actor->userId ?? throw new LogicException('A correction is approved by an account.'),
                'actor_role' => $approverRole,
                'scorecard_calculation_id' => $calculation->id,
                'comment' => "Koreksi {$correctionPublicId}: {$reason}",
                'decided_at' => CarbonImmutable::now(),
            ]);

            $this->audit->record(new AuditEntry(
                action: 'scorecard_review.recalculated',
                entityType: 'scorecard_review',
                entityId: $review->public_id,
                before: ['state' => $from->value],
                after: ['state' => $review->state->value, 'round' => $review->round, 'calculation' => $calculation->public_id, 'final_score' => $calculation->final_score, 'rating' => $calculation->rating_key, 'correction' => $correctionPublicId],
                reason: $reason,
                context: $actor->audit,
            ));

            if ($period->status !== PeriodStatus::Review) {
                $this->outbox->publish(new OutboxEvent(
                    eventType: 'FinalScoreCorrected',
                    eventVersion: 1,
                    aggregateType: 'scorecard_review',
                    aggregateId: $review->public_id,
                    payload: [
                        'period' => $period->public_id,
                        'calculation' => $calculation->public_id,
                        'correction' => $correctionPublicId,
                        'previous_final_score' => $previous['score'],
                        'previous_rating' => $previous['rating_key'],
                        'final_score' => $calculation->final_score,
                        'rating' => $calculation->rating_key,
                    ],
                    deduplicationKey: "final-score-corrected:{$review->public_id}:{$calculation->public_id}",
                ));
            }

            $changed++;
        }

        return $changed;
    }

    /**
     * The score that counts for a review: the calibrated score of the
     * current round when it was made for the current calculation, otherwise
     * the calculated score.
     *
     * @return array{score: string|null, rating_key: string|null, rating_label: string|null, adjusted: bool}
     */
    public static function effectiveScore(ScorecardReview $review, ScorecardCalculation $calculation): array
    {
        $calibration = $review->currentCalibration();

        if ($calibration !== null) {
            return [
                'score' => $calibration->calibrated_final_score,
                'rating_key' => $calibration->calibrated_rating_key,
                'rating_label' => $calibration->calibrated_rating_label,
                'adjusted' => $calibration->is_adjusted,
            ];
        }

        return ['score' => $calculation->final_score, 'rating_key' => $calculation->rating_key, 'rating_label' => $calculation->rating_label, 'adjusted' => false];
    }

    public function bypassFor(PeriodAssignment $assignment): ?ApprovalStageBypass
    {
        if (! array_key_exists(ApprovalStage::Review->value, $assignment->approver_gaps ?? [])) {
            return null;
        }

        return ApprovalStageBypass::query()
            ->inForceOn($this->access->businessToday())
            ->where('organization_unit_id', $assignment->organization_unit_id)
            ->where('stage', ApprovalStage::Review->value)
            ->orderBy('id')
            ->first();
    }

    public static function latestCalculation(int $assignmentId): ?ScorecardCalculation
    {
        return ScorecardCalculation::query()->where('period_assignment_id', $assignmentId)->orderByDesc('sequence_number')->first();
    }

    /**
     * @return array<int, ScorecardCalculation> period assignment id => latest calculation
     */
    public static function latestCalculations(int $periodId): array
    {
        $latest = [];

        $calculations = ScorecardCalculation::query()
            ->where('assessment_period_id', $periodId)
            ->orderBy('sequence_number')
            ->get(['id', 'public_id', 'period_assignment_id', 'sequence_number', 'status', 'final_score', 'rating_key', 'rating_label']);

        foreach ($calculations as $calculation) {
            $latest[$calculation->period_assignment_id] = $calculation;
        }

        return $latest;
    }

    /**
     * @return array{0: ScorecardReview, 1: PeriodAssignment}
     */
    private function open(int $reviewId): array
    {
        $review = ScorecardReview::query()->lockForUpdate()->findOrFail($reviewId);
        $period = AssessmentPeriod::query()->sharedLock()->findOrFail($review->assessment_period_id);

        if ($period->status !== PeriodStatus::Review) {
            throw new BusinessRuleViolation('period_not_in_review', 'Keputusan approval hanya dapat diambil selama periode berada pada tahap Review.');
        }

        $review->setRelation('period', $period);
        $assignment = PeriodAssignment::query()->with(['approvers', 'employee'])->findOrFail($review->period_assignment_id);

        return [$review, $assignment];
    }

    private static function pending(ScorecardReview $review): ApprovalStage
    {
        return $review->pendingStage() ?? throw new BusinessRuleViolation('stage_not_pending', 'Scorecard ini tidak sedang menunggu keputusan.');
    }

    private function authorize(User $user, PeriodAssignment $assignment, ApprovalStage $stage): StageAuthority
    {
        if ($assignment->employee?->user_id === $user->id) {
            throw new BusinessRuleViolation('self_approval', 'Anda tidak dapat memutuskan scorecard Anda sendiri.');
        }

        return $this->authority->resolve($user, $assignment, $stage) ?? throw new AuthorizationException;
    }

    /**
     * One person decides at most one stage of a round, also when they hold
     * several roles or act through a delegation: both the acting account
     * and the approver it acts for count.
     */
    private function assertNotAlreadyActed(ScorecardReview $review, User $user, StageAuthority $authority): void
    {
        $people = $authority->actingForUserId === null ? [$user->id] : [$user->id, $authority->actingForUserId];

        $acted = ScorecardReviewDecision::query()
            ->where('scorecard_review_id', $review->id)
            ->where('round', $review->round)
            ->where('outcome', DecisionOutcome::Approved->value)
            ->where(fn (Builder $who) => $who->whereIn('actor_user_id', $people)->orWhereIn('acting_for_user_id', $people))
            ->exists();

        if ($acted) {
            throw new BusinessRuleViolation('duplicate_approver', 'Anda, atau approver yang Anda wakili, sudah memutuskan tahap lain scorecard ini pada putaran yang sama. Tahap berikutnya harus diputuskan oleh orang lain.');
        }
    }

    /**
     * The calculation under review must still be the latest complete one.
     */
    private static function currentCalculation(ScorecardReview $review): ScorecardCalculation
    {
        $latest = self::latestCalculation($review->period_assignment_id);

        if ($latest === null || $latest->id !== $review->scorecard_calculation_id) {
            throw new BusinessRuleViolation('scorecard_stale', 'Scorecard ini dihitung ulang setelah masuk review. HR perlu mengajukannya ulang dengan perhitungan terbaru.');
        }

        if ($latest->status !== ScorecardStatus::Complete || $latest->final_score === null || $latest->rating_key === null) {
            throw new BusinessRuleViolation('scorecard_incomplete', 'Perhitungan scorecard ini belum lengkap.');
        }

        return $latest;
    }

    /**
     * @return array{score: string, rating_key: string, rating_label: string, adjusted: bool}
     */
    private function calibration(ScorecardCalculation $calculation, ?string $calibratedScore, ?string $reason): array
    {
        $original = BigDecimal::of((string) $calculation->final_score);
        $input = $calibratedScore === null ? '' : trim($calibratedScore);

        if ($input === '') {
            return ['score' => (string) $calculation->final_score, 'rating_key' => (string) $calculation->rating_key, 'rating_label' => (string) $calculation->rating_label, 'adjusted' => false];
        }

        if (preg_match(self::SCORE, $input) !== 1) {
            throw new BusinessRuleViolation('invalid_score', 'Skor kalibrasi harus angka desimal dengan titik sebagai pemisah (maks. 4 desimal).');
        }

        try {
            $policy = ScorecardPolicy::fromArray($calculation->policy);
        } catch (InvalidArgumentException) {
            throw new BusinessRuleViolation('policy_unreadable', 'Aturan scorecard pada perhitungan ini tidak dapat dibaca, sehingga skor tidak dapat dikalibrasi.');
        }

        $score = BigDecimal::of($input);

        if ($score->getScale() > $policy->finalRounding->scale) {
            throw new BusinessRuleViolation('invalid_score', "Skor kalibrasi maksimal {$policy->finalRounding->scale} desimal, sesuai aturan pembulatan skor akhir.");
        }

        $band = $policy->ratingFor($score) ?? throw new BusinessRuleViolation('calibration_out_of_range', 'Skor kalibrasi berada di luar seluruh rating band aturan scorecard.');
        $adjusted = ! $score->isEqualTo($original);

        if ($adjusted && $reason === null) {
            throw new BusinessRuleViolation('reason_required', 'Perubahan skor pada kalibrasi wajib disertai alasan.');
        }

        return ['score' => (string) $score->toScale(4), 'rating_key' => $band->key, 'rating_label' => $band->label, 'adjusted' => $adjusted];
    }

    private function decide(ScorecardReview $review, string $stage, DecisionOutcome $outcome, User $user, StageAuthority $authority, ?string $comment, ?int $bypassId): void
    {
        ScorecardReviewDecision::query()->create([
            'scorecard_review_id' => $review->id,
            'round' => $review->round,
            'stage' => $stage,
            'outcome' => $outcome,
            'actor_user_id' => $user->id,
            'actor_role' => $authority->grant->role->value,
            'acting_for_user_id' => $authority->actingForUserId,
            'grant_source' => $authority->grant->source,
            'grant_reference' => $authority->grant->sourcePublicId,
            'approval_stage_bypass_id' => $bypassId,
            'scorecard_calculation_id' => $review->scorecard_calculation_id,
            'comment' => $comment,
            'decided_at' => CarbonImmutable::now(),
        ]);
    }

    private function record(Actor $actor, ScorecardReview $review, string $action, ReviewState $from, StageAuthority $authority, ?string $reason): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'scorecard_review',
            entityId: $review->public_id,
            before: ['state' => $from->value],
            after: ['state' => $review->state->value, 'round' => $review->round, ...$authority->toAudit()],
            reason: $reason,
            context: $actor->audit,
        ));
    }

    /**
     * Announces that a stage now waits for its approvers: one scorecard, or
     * (review null) every scorecard of the period.
     */
    private function stageReady(AssessmentPeriod $period, ApprovalStage $stage, ?ScorecardReview $review, string $occasion): void
    {
        $subject = $review === null ? $period->public_id : $review->public_id;

        $this->outbox->publish(new OutboxEvent(
            eventType: 'ApprovalStageReady',
            eventVersion: 1,
            aggregateType: $review === null ? 'assessment_period' : 'scorecard_review',
            aggregateId: $subject,
            payload: ['period' => $period->public_id, 'stage' => $stage->value, 'review' => $review?->public_id, 'occasion' => $occasion],
            deduplicationKey: "stage-ready:{$subject}:{$stage->value}:{$occasion}",
        ));
    }

    private static function assertSameAccount(Actor $actor, User $user): void
    {
        if ($actor->userId !== $user->id) {
            throw new LogicException('The audit actor and the deciding account must be the same.');
        }
    }
}
