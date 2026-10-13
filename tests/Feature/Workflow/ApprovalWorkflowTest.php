<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\IdentityAccess\Application\DelegationService;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Domain\Exceptions\SafeDomainException;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use App\Modules\Workflow\Application\WorkflowQuery;
use App\Modules\Workflow\Application\WorkflowService;
use App\Modules\Workflow\Domain\DecisionOutcome;
use App\Modules\Workflow\Domain\ReviewState;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardCalibration;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReviewDecision;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\ScorecardScenario;
use Tests\Support\WorkflowScenario;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
});

test('a period enters review only with verified actuals and complete scorecards', function (): void {
    $scorecards = new ScorecardScenario;
    ScorecardScenario::enablePolicy();
    $scorecards->standardActuals();
    $scorecards->calculate();

    // W-002 has no quality actual: its scorecard is incomplete.
    $rule = AccessFixtures::violatedRule(fn () => app(PeriodService::class)->advance(
        WorkflowScenario::actor($scorecards->hr()), $scorecards->period->id, $scorecards->period->refresh()->lock_version, PeriodStatus::Review,
    ));

    expect($rule)->toBe('period_not_ready')
        ->and($scorecards->period->refresh()->status)->toBe(PeriodStatus::InProgress)
        ->and(ScorecardReview::query()->count())->toBe(0);
});

test('entering review opens one review per covered scorecard on its latest calculation', function (): void {
    $scenario = new WorkflowScenario;

    expect($scenario->period->status)->toBe(PeriodStatus::Review)
        ->and(ScorecardReview::query()->count())->toBe(4);

    foreach (['W-001', 'W-002', 'PROD-WOVEN', 'AR-001'] as $subject) {
        $review = $scenario->review($subject);

        expect($review->state)->toBe(ReviewState::InReview)
            ->and($review->round)->toBe(1)
            ->and($review->scorecard_calculation_id)->toBe(WorkflowService::latestCalculation($review->period_assignment_id)?->id);
    }
});

test('happy path: review, calibration, and final approval are recorded with role and grant, then the period is finalized and locked', function (): void {
    $scenario = new WorkflowScenario;

    expect($scenario->approve($scenario->manager, 'W-001', 'Sesuai data')->state)->toBe(ReviewState::Reviewed)
        ->and($scenario->calibrate($scenario->hr(), 'W-001')->state)->toBe(ReviewState::Calibrated)
        ->and($scenario->approve($scenario->management, 'W-001')->state)->toBe(ReviewState::FinalApproved);

    $decisions = ScorecardReviewDecision::query()->where('scorecard_review_id', $scenario->review('W-001')->id)->orderBy('id')->get();

    expect($decisions->map(fn ($decision) => [$decision->stage, $decision->outcome->value, $decision->actor_role, $decision->actor_user_id])->all())->toBe([
        ['review', 'approved', 'department_head', $scenario->manager->id],
        ['calibration', 'approved', 'hr_performance', $scenario->hr()->id],
        ['final_approval', 'approved', 'management', $scenario->management->id],
    ])
        ->and($decisions[0]->grant_source)->toBe('assignment')
        ->and($decisions[0]->comment)->toBe('Sesuai data')
        ->and(OutboxMessage::query()->where('event_type', 'CalibrationCompleted')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'FinalApprovalCompleted')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'scorecard_review.final_approved')->count())->toBe(1);

    // The period cannot be finalized while other scorecards are open.
    expect(AccessFixtures::violatedRule(fn () => $scenario->advance(PeriodStatus::Finalized)))->toBe('period_not_ready');

    $scenario->bypass();
    $scenario->approveFully('W-002');
    $scenario->approveFully('PROD-WOVEN');
    $scenario->calibrate($scenario->hr(), 'AR-001');
    $scenario->approve($scenario->management, 'AR-001');

    expect($scenario->advance(PeriodStatus::Finalized)->status)->toBe(PeriodStatus::Finalized)
        ->and($scenario->advance(PeriodStatus::Locked)->status)->toBe(PeriodStatus::Locked)
        ->and(OutboxMessage::query()->where('event_type', 'PeriodLocked')->count())->toBe(1);

    // Nothing moves backwards, and no approval is taken on a locked period.
    expect(fn () => $scenario->advance(PeriodStatus::Review))->toThrow(SafeDomainException::class)
        ->and(AccessFixtures::violatedRule(fn () => $scenario->approve($scenario->manager, 'W-001')))->toBe('period_not_in_review');
});

test('a period never moves backwards', function (): void {
    $scenario = new WorkflowScenario;

    expect(fn () => $scenario->advance(PeriodStatus::InProgress))->toThrow(SafeDomainException::class)
        ->and($scenario->period->refresh()->status)->toBe(PeriodStatus::Review);
});

test('a stage without reviewer waits; only a signed bypass in force lets calibration proceed', function (): void {
    $scenario = new WorkflowScenario;
    $review = $scenario->review('AR-001');

    // Nobody holds the review stage for ACC-AR: no account can approve it.
    foreach ([$scenario->manager, $scenario->hr(), $scenario->management, $scenario->scorecards->leader] as $user) {
        expect(fn () => $scenario->approve($user, 'AR-001'))->toThrow(AuthorizationException::class);
    }

    expect(AccessFixtures::violatedRule(fn () => $scenario->calibrate($scenario->hr(), 'AR-001')))->toBe('review_pending')
        ->and(app(WorkflowQuery::class)->row($review, $scenario->hr())->waitingOn)->toContain('Belum dikonfigurasi');

    $bypass = $scenario->bypass();

    expect(app(WorkflowQuery::class)->row($review->refresh(), $scenario->hr())->viaBypass)->toBeTrue()
        ->and($scenario->calibrate($scenario->hr(), 'AR-001')->state)->toBe(ReviewState::Calibrated);

    $decisions = ScorecardReviewDecision::query()->where('scorecard_review_id', $review->id)->orderBy('id')->get();

    expect($decisions)->toHaveCount(2)
        ->and($decisions[0]->stage)->toBe('review')
        ->and($decisions[0]->outcome)->toBe(DecisionOutcome::Bypassed)
        ->and($decisions[0]->approval_stage_bypass_id)->toBe($bypass->id)
        ->and($decisions[1]->stage)->toBe('calibration');
});

test('a bypass applies only to a team without reviewer, and not after it expires or is revoked', function (): void {
    $scenario = new WorkflowScenario;

    // PROD-WOVEN has a reviewer: a bypass for it changes nothing.
    $scenario->bypass('PROD-WOVEN');
    expect(AccessFixtures::violatedRule(fn () => $scenario->calibrate($scenario->hr(), 'W-001')))->toBe('review_pending');

    $scenario->bypass('ACC-AR', AccessFixtures::TODAY, '2026-10-20');

    $this->travelTo(CarbonImmutable::parse('2026-10-20 01:00:00', 'UTC'));
    WorkflowScenario::flushAccess();

    expect(AccessFixtures::violatedRule(fn () => $scenario->calibrate($scenario->hr(), 'AR-001')))->toBe('review_pending');
});

test('a returned scorecard is resubmitted by HR as a new round and keeps its history', function (): void {
    $scenario = new WorkflowScenario;
    $workflow = app(WorkflowService::class);
    $review = $scenario->review('W-001');

    expect(AccessFixtures::violatedRule(fn () => $workflow->returnForRevision(WorkflowScenario::actor($scenario->manager), $scenario->manager, $review->id, $review->lock_version, '  ')))->toBe('comment_required');

    $review = $workflow->returnForRevision(WorkflowScenario::actor($scenario->manager), $scenario->manager, $review->id, $review->lock_version, 'Data mutu perlu dicek ulang');

    expect($review->state)->toBe(ReviewState::Returned)
        ->and(AccessFixtures::violatedRule(fn () => $scenario->approve($scenario->manager, 'W-001')))->toBe('stage_not_pending')
        ->and(fn () => $workflow->resubmit(WorkflowScenario::actor($scenario->manager), $scenario->manager, $review->id, $review->lock_version, 'Sudah dicek'))->toThrow(AuthorizationException::class);

    $review = $workflow->resubmit(WorkflowScenario::actor($scenario->hr()), $scenario->hr(), $review->id, $review->lock_version, 'Data sudah dicek, tidak berubah');

    expect($review->state)->toBe(ReviewState::InReview)
        ->and($review->round)->toBe(2);

    // The manager who returned it may review the new round.
    expect($scenario->approve($scenario->manager, 'W-001')->state)->toBe(ReviewState::Reviewed);

    $history = ScorecardReviewDecision::query()->where('scorecard_review_id', $review->id)->orderBy('id')->get()
        ->map(fn ($decision) => [$decision->round, $decision->stage, $decision->outcome->value])->all();

    expect($history)->toBe([
        [1, 'review', 'returned'],
        [2, 'resubmission', 'resubmitted'],
        [2, 'review', 'approved'],
    ]);
});

test('a delegate decides for the approver only while the delegation is in force', function (): void {
    $scenario = new WorkflowScenario;
    $delegate = AccessFixtures::user('Delegasi manager');
    $source = RoleAssignment::query()->where('user_id', $scenario->manager->id)->firstOrFail();

    // Without a delegation the account has no authority at all.
    expect(fn () => $scenario->approve($delegate, 'W-001'))->toThrow(AuthorizationException::class);

    app(DelegationService::class)->create(Actor::system('test'), $source->id, $delegate->id, AccessFixtures::unit('PROD-WOVEN')->id, AccessFixtures::TODAY, '2026-10-20', 'Cuti');
    WorkflowScenario::flushAccess();

    expect($scenario->approve($delegate, 'W-001')->state)->toBe(ReviewState::Reviewed);

    $decision = ScorecardReviewDecision::query()->where('scorecard_review_id', $scenario->review('W-001')->id)->firstOrFail();

    expect($decision->actor_user_id)->toBe($delegate->id)
        ->and($decision->acting_for_user_id)->toBe($scenario->manager->id)
        ->and($decision->grant_source)->toBe('delegation');

    // The delegation ends on 2026-10-20 (exclusive): no authority afterwards.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 01:00:00', 'UTC'));
    WorkflowScenario::flushAccess();

    expect(fn () => $scenario->approve($delegate, 'W-002'))->toThrow(AuthorizationException::class)
        ->and($scenario->review('W-002')->state)->toBe(ReviewState::InReview);
});

test('nobody decides their own scorecard, whatever roles they hold', function (): void {
    // The assessed employee W-001 also holds the reviewer and final approver roles.
    $scenario = new WorkflowScenario(true, function (ScorecardScenario $scorecards): void {
        AccessFixtures::grant($scorecards->staff, Role::DepartmentHead, 'PROD');
        AccessFixtures::grant($scorecards->staff, Role::Management, 'BAL');
    });
    $staff = $scenario->scorecards->staff;

    expect(AccessFixtures::violatedRule(fn () => $scenario->approve($staff, 'W-001')))->toBe('self_approval');

    $scenario->approve($scenario->manager, 'W-001');
    $scenario->calibrate($scenario->hr(), 'W-001');

    expect(AccessFixtures::violatedRule(fn () => $scenario->approve($staff, 'W-001')))->toBe('self_approval')
        ->and($scenario->review('W-001')->state)->toBe(ReviewState::Calibrated);

    // The same account may still decide a colleague's scorecard.
    expect($scenario->approve($staff, 'W-002')->state)->toBe(ReviewState::Reviewed);
});

test('one account decides at most one stage of a round', function (): void {
    // HR also holds the Management role, so it is in the final approver snapshot.
    $scenario = new WorkflowScenario(true, function (ScorecardScenario $scorecards): void {
        AccessFixtures::grant($scorecards->hr(), Role::Management, 'BAL');
    });

    $scenario->approve($scenario->manager, 'W-001');
    $scenario->calibrate($scenario->hr(), 'W-001');

    expect(AccessFixtures::violatedRule(fn () => $scenario->approve($scenario->hr(), 'W-001')))->toBe('duplicate_approver')
        ->and($scenario->approve($scenario->management, 'W-001')->state)->toBe(ReviewState::FinalApproved);
});

test('a double click produces exactly one decision', function (): void {
    $scenario = new WorkflowScenario;
    $workflow = app(WorkflowService::class);
    $review = $scenario->review('W-001');
    $actor = WorkflowScenario::actor($scenario->manager);

    $workflow->approve($actor, $scenario->manager, $review->id, $review->lock_version);

    expect(fn () => $workflow->approve($actor, $scenario->manager, $review->id, $review->lock_version))->toThrow(SafeDomainException::class)
        ->and(ScorecardReviewDecision::query()->where('scorecard_review_id', $review->id)->count())->toBe(1)
        ->and($scenario->review('W-001')->state)->toBe(ReviewState::Reviewed);
});

test('two approvers acting on the same version produce one calibration', function (): void {
    $scenario = new WorkflowScenario;
    $workflow = app(WorkflowService::class);
    $scenario->approve($scenario->manager, 'W-001');
    $review = $scenario->review('W-001');

    $workflow->calibrate(WorkflowScenario::actor($scenario->hr()), $scenario->hr(), $review->id, $review->lock_version, null, null);

    expect(fn () => $workflow->calibrate(WorkflowScenario::actor($scenario->secondHr()), $scenario->secondHr(), $review->id, $review->lock_version, '90', 'Penyesuaian'))->toThrow(SafeDomainException::class)
        ->and(ScorecardCalibration::query()->where('scorecard_review_id', $review->id)->count())->toBe(1)
        ->and(ScorecardCalibration::query()->where('scorecard_review_id', $review->id)->firstOrFail()->is_adjusted)->toBeFalse();
});

test('calibration keeps the calculated score next to the calibrated one and rates it with the stored policy', function (): void {
    $scenario = new WorkflowScenario;
    $scenario->approve($scenario->manager, 'W-001');
    $calculation = ScorecardCalculation::query()->findOrFail($scenario->review('W-001')->scorecard_calculation_id);
    $before = $calculation->getAttributes();

    expect(AccessFixtures::violatedRule(fn () => $scenario->calibrate($scenario->hr(), 'W-001', '90')))->toBe('reason_required')
        ->and(AccessFixtures::violatedRule(fn () => $scenario->calibrate($scenario->hr(), 'W-001', '90.123', 'Alasan')))->toBe('invalid_score')
        ->and(AccessFixtures::violatedRule(fn () => $scenario->calibrate($scenario->hr(), 'W-001', '9,5', 'Alasan')))->toBe('invalid_score')
        ->and($scenario->review('W-001')->state)->toBe(ReviewState::Reviewed);

    $review = $scenario->calibrate($scenario->hr(), 'W-001', '90', 'Disamakan dengan hasil forum kalibrasi');
    $calibration = ScorecardCalibration::query()->where('scorecard_review_id', $review->id)->firstOrFail();

    expect($calibration->is_adjusted)->toBeTrue()
        ->and($calibration->original_final_score)->toBe($calculation->final_score)
        ->and($calibration->original_rating_key)->toBe($calculation->rating_key)
        ->and($calibration->calibrated_final_score)->toBe('90.0000')
        ->and($calibration->calibrated_rating_key)->toBe('r3')
        ->and($calibration->reason)->toBe('Disamakan dengan hasil forum kalibrasi')
        ->and($calculation->refresh()->getAttributes())->toBe($before)
        ->and(WorkflowService::effectiveScore($review->load('calibrations'), $calculation))->toMatchArray(['score' => '90.0000', 'rating_key' => 'r3', 'adjusted' => true]);
});

test('an account without the stage role cannot decide, and no decision is taken outside review', function (): void {
    $scenario = new WorkflowScenario;

    expect(fn () => $scenario->approve($scenario->scorecards->leader, 'W-001'))->toThrow(AuthorizationException::class)
        ->and(fn () => $scenario->approve($scenario->management, 'W-001'))->toThrow(AuthorizationException::class)
        ->and(fn () => $scenario->calibrate($scenario->manager, 'W-001'))->toThrow(AuthorizationException::class);

    $scenario->approve($scenario->manager, 'W-001');

    expect(fn () => $scenario->calibrate($scenario->management, 'W-001'))->toThrow(AuthorizationException::class)
        ->and(AccessFixtures::violatedRule(fn () => $scenario->approve($scenario->hr(), 'W-001')))->toBe('calibration_required');
});

test('bulk approval reports the outcome per scorecard', function (): void {
    $scenario = new WorkflowScenario;
    $workflow = app(WorkflowService::class);
    $locks = [];

    foreach (['W-001', 'W-002', 'AR-001'] as $subject) {
        $locks[$scenario->review($subject)->id] = $scenario->review($subject)->lock_version;
    }

    $results = $workflow->approveMany(WorkflowScenario::actor($scenario->manager), $scenario->manager, $locks, 'Disetujui bersama');

    expect($results[$scenario->review('W-001')->id])->toBeNull()
        ->and($results[$scenario->review('W-002')->id])->toBeNull()
        ->and($results[$scenario->review('AR-001')->id])->toBeString()
        ->and($scenario->review('W-001')->state)->toBe(ReviewState::Reviewed)
        ->and($scenario->review('AR-001')->state)->toBe(ReviewState::InReview)
        ->and(AccessFixtures::violatedRule(fn () => $workflow->approveMany(WorkflowScenario::actor($scenario->manager), $scenario->manager, [])))->toBe('nothing_selected');
});

test('the inbox lists only what the account may decide now', function (): void {
    $scenario = new WorkflowScenario;
    $query = app(WorkflowQuery::class);
    $codes = fn ($user): array => $query->inbox($user)->map(fn ($row) => $row->review->assignment->subject_code)->sort()->values()->all();

    expect($codes($scenario->manager))->toBe(['PROD-WOVEN', 'W-001', 'W-002'])
        ->and($codes($scenario->hr()))->toBe([])
        ->and($codes($scenario->management))->toBe([])
        ->and($codes($scenario->scorecards->staff))->toBe([]);

    $scenario->approve($scenario->manager, 'W-001');
    $scenario->bypass();

    expect($codes($scenario->manager))->toBe(['PROD-WOVEN', 'W-002'])
        ->and($codes($scenario->hr()))->toBe(['AR-001', 'W-001']);
});

test('the database refuses invalid review transitions and changes to the history', function (): void {
    $scenario = new WorkflowScenario;
    $scenario->approve($scenario->manager, 'W-001');
    $review = $scenario->review('W-001');
    $decision = ScorecardReviewDecision::query()->where('scorecard_review_id', $review->id)->firstOrFail();

    $attempts = [
        fn () => DB::table('scorecard_reviews')->where('id', $scenario->review('W-002')->id)->update(['state' => 'final_approved']),
        fn () => DB::table('scorecard_reviews')->where('id', $review->id)->update(['period_assignment_id' => $scenario->review('W-002')->period_assignment_id + 1000]),
        fn () => DB::table('scorecard_reviews')->where('id', $review->id)->delete(),
        fn () => DB::table('scorecard_review_decisions')->where('id', $decision->id)->update(['comment' => 'diubah']),
        fn () => DB::table('scorecard_review_decisions')->where('id', $decision->id)->delete(),
        fn () => DB::table('assessment_periods')->where('id', $scenario->period->id)->update(['status' => 'in_progress']),
    ];

    foreach ($attempts as $attempt) {
        expect(fn () => DB::transaction($attempt))->toThrow(QueryException::class);
    }

    expect($scenario->review('W-001')->state)->toBe(ReviewState::Reviewed)
        ->and($decision->refresh()->comment)->toBeNull();
});

test('a scorecard recalculated after entering review is resubmitted by HR, whatever stage it had reached', function (): void {
    $scenario = new WorkflowScenario;
    $workflow = app(WorkflowService::class);
    $scenario->approveFully('W-001');
    $hr = $scenario->hr();
    $review = $scenario->review('W-001');

    // Nothing to resubmit while the calculation under review is the latest one.
    expect(AccessFixtures::violatedRule(fn () => $workflow->resubmit(WorkflowScenario::actor($hr), $hr, $review->id, $review->lock_version, 'Coba')))->toBe('stage_not_pending');

    // A new policy version produces a new calculation for every scorecard.
    ScorecardScenario::enablePolicy(['version' => 2]);
    $scenario->scorecards->calculate('Versi aturan baru');

    expect(AccessFixtures::violatedRule(fn () => $scenario->approve($scenario->manager, 'W-002')))->toBe('scorecard_stale')
        ->and(AccessFixtures::violatedRule(fn () => $scenario->advance(PeriodStatus::Finalized)))->toBe('period_not_ready')
        ->and(app(WorkflowQuery::class)->toResubmit($hr))->toHaveCount(4)
        ->and(app(WorkflowQuery::class)->toResubmit($scenario->manager))->toHaveCount(0);

    $review = $workflow->resubmit(WorkflowScenario::actor($hr), $hr, $review->id, $review->lock_version, 'Dihitung ulang dengan aturan versi 2');

    expect($review->state)->toBe(ReviewState::InReview)
        ->and($review->round)->toBe(2)
        ->and($review->scorecard_calculation_id)->toBe(WorkflowService::latestCalculation($review->period_assignment_id)?->id)
        ->and($scenario->approve($scenario->manager, 'W-001')->state)->toBe(ReviewState::Reviewed);
});

test('an approver cannot decide a second stage of the round through a delegate', function (): void {
    // The reviewer also holds the HR role, so they are a snapshot approver of two stages.
    $scenario = new WorkflowScenario(true, function (): void {
        $head = RoleAssignment::query()->where('role', Role::DepartmentHead->value)->orderByDesc('id')->firstOrFail();
        AccessFixtures::grant(User::query()->findOrFail($head->user_id), Role::HrPerformance, 'BAL');
    });
    $delegate = AccessFixtures::user('Delegasi manager');
    $source = RoleAssignment::query()->where('user_id', $scenario->manager->id)->where('role', Role::DepartmentHead->value)->firstOrFail();
    app(DelegationService::class)->create(Actor::system('test'), $source->id, $delegate->id, AccessFixtures::unit('PROD-WOVEN')->id, AccessFixtures::TODAY, '2026-10-20', 'Cuti');
    WorkflowScenario::flushAccess();

    // The delegate reviews for the manager, so the manager has decided this round.
    $scenario->approve($delegate, 'W-001');

    expect(AccessFixtures::violatedRule(fn () => $scenario->calibrate($scenario->manager, 'W-001')))->toBe('duplicate_approver')
        ->and($scenario->calibrate($scenario->hr(), 'W-001')->state)->toBe(ReviewState::Calibrated);

    // The other way round: the manager calibrates W-002 after a colleague's
    // review is not possible either once their delegate reviewed it.
    $scenario->approve($delegate, 'W-002');

    expect(AccessFixtures::violatedRule(fn () => $scenario->calibrate($scenario->manager, 'W-002')))->toBe('duplicate_approver');
});

test('a bypass is registered for a team only, not retroactively, and not twice for the same dates', function (): void {
    $scenario = new WorkflowScenario;

    expect(AccessFixtures::violatedRule(fn () => $scenario->bypass('ACC')))->toBe('bypass_team_only')
        ->and(AccessFixtures::violatedRule(fn () => $scenario->bypass('ACC-AR', '2026-10-01')))->toBe('retroactive_change');

    $scenario->bypass('ACC-AR');

    expect(AccessFixtures::violatedRule(fn () => $scenario->bypass('ACC-AR', '2026-10-15')))->toBe('bypass_overlap');
});
