<?php

declare(strict_types=1);

use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Shared\Domain\Exceptions\ConcurrencyConflict;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use App\Modules\Workflow\Application\CorrectionService;
use App\Modules\Workflow\Application\WorkflowQuery;
use App\Modules\Workflow\Application\WorkflowService;
use App\Modules\Workflow\Domain\CorrectionStatus;
use App\Modules\Workflow\Domain\ReviewState;
use App\Modules\Workflow\Infrastructure\Persistence\Models\CorrectionRequest;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReviewDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\ScorecardScenario;
use Tests\Support\WorkflowScenario;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    $this->scenario = new WorkflowScenario;
    $this->corrections = app(CorrectionService::class);
});

function requestCorrection(object $test, string $value = '80', string $kpi = 'SC-QUALITY', string $subject = 'W-001'): CorrectionRequest
{
    $hr = $test->scenario->hr();

    return $test->corrections->request(WorkflowScenario::actor($hr), $hr, $test->scenario->actual($kpi, $subject)->id, $value, null, 'Salah input laporan mutu', 'BA-TEST-001');
}

function approveCorrection(object $test, CorrectionRequest $request): CorrectionRequest
{
    $management = $test->scenario->management;

    return $test->corrections->approve(WorkflowScenario::actor($management), $management, $request->id, $request->lock_version, 'Disetujui');
}

test('a correction in review writes a new verified version, recalculates, and restarts the approval of the affected scorecard', function (): void {
    $scenario = $this->scenario;
    $scenario->approveFully('W-001');
    $scenario->approve($scenario->manager, 'W-002');
    $actual = $scenario->actual('SC-QUALITY', 'W-001');
    $oldCalculation = $scenario->review('W-001')->scorecard_calculation_id;
    $otherCalculation = $scenario->review('W-002')->scorecard_calculation_id;

    $request = requestCorrection($this);

    expect($request->status)->toBe(CorrectionStatus::Pending)
        ->and(OutboxMessage::query()->where('event_type', 'CorrectionRequested')->count())->toBe(1);

    $request = approveCorrection($this, $request);
    $versions = KpiActualVersion::query()->where('kpi_actual_id', $actual->id)->orderBy('version_number')->get();

    expect($request->status)->toBe(CorrectionStatus::Approved)
        ->and($versions)->toHaveCount(2)
        ->and($versions[0]->actual_value)->toBe('95.5000')
        ->and($versions[0]->status)->toBe(ActualStatus::Verified)
        ->and($versions[1]->actual_value)->toBe('80.0000')
        ->and($versions[1]->status)->toBe(ActualStatus::Verified)
        ->and($versions[1]->source)->toBe(KpiActualVersion::SOURCE_CORRECTION)
        ->and($versions[1]->previous_version_id)->toBe($versions[0]->id)
        ->and($versions[1]->submitted_by_user_id)->toBe($scenario->hr()->id)
        ->and($versions[1]->verified_by_user_id)->toBe($scenario->management->id)
        ->and($request->new_version_id)->toBe($versions[1]->id);

    $review = $scenario->review('W-001');
    $newCalculation = ScorecardCalculation::query()->findOrFail($review->scorecard_calculation_id);

    expect($review->state)->toBe(ReviewState::InReview)
        ->and($review->round)->toBe(2)
        ->and($newCalculation->id)->not->toBe($oldCalculation)
        ->and($newCalculation->previous_calculation_id)->toBe($oldCalculation)
        ->and(ScorecardCalculation::query()->whereKey($oldCalculation)->exists())->toBeTrue()
        // A scorecard whose inputs did not change keeps its calculation and its progress.
        ->and($scenario->review('W-002')->scorecard_calculation_id)->toBe($otherCalculation)
        ->and($scenario->review('W-002')->state)->toBe(ReviewState::Reviewed)
        ->and(ScorecardReviewDecision::query()->where('scorecard_review_id', $review->id)->where('stage', 'correction')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'CorrectionApproved')->count())->toBe(1)
        ->and(AuditLog::query()->whereIn('action', ['correction.requested', 'correction.approved', 'actual.corrected', 'scorecard_review.recalculated'])->count())->toBe(4);
});

test('a correction needs two people and the right roles', function (): void {
    $scenario = $this->scenario;
    $actualId = $scenario->actual('SC-QUALITY', 'W-001')->id;

    // The manager and the team leader may not request; HR may not approve.
    foreach ([$scenario->manager, $scenario->scorecards->leader, $scenario->management] as $user) {
        expect(fn () => $this->corrections->request(WorkflowScenario::actor($user), $user, $actualId, '80', null, 'Alasan'))->toThrow(AuthorizationException::class);
    }

    $request = requestCorrection($this);
    $second = $scenario->secondHr();

    expect(fn () => $this->corrections->approve(WorkflowScenario::actor($second), $second, $request->id, $request->lock_version))->toThrow(AuthorizationException::class)
        ->and(AccessFixtures::violatedRule(fn () => requestCorrection($this, '70')))->toBe('correction_pending')
        ->and($request->refresh()->status)->toBe(CorrectionStatus::Pending);
});

test('a correction request is validated like an actual and decided once', function (): void {
    $scenario = $this->scenario;
    $hr = $scenario->hr();
    $actualId = $scenario->actual('SC-QUALITY', 'W-001')->id;
    $ask = fn (?string $value, ?string $note = null, string $reason = 'Alasan') => $this->corrections->request(WorkflowScenario::actor($hr), $hr, $actualId, $value, $note, $reason);

    expect(AccessFixtures::violatedRule(fn () => $ask('95.5')))->toBe('no_change')
        ->and(AccessFixtures::violatedRule(fn () => $ask('abc')))->toBe('invalid_value')
        ->and(AccessFixtures::violatedRule(fn () => $ask(null)))->toBe('note_required')
        ->and(AccessFixtures::violatedRule(fn () => $ask('80', null, ' ')))->toBe('reason_required');

    $request = $ask('80');
    $management = $scenario->management;
    $rejected = $this->corrections->reject(WorkflowScenario::actor($management), $management, $request->id, $request->lock_version, 'Bukti tidak cukup');

    expect($rejected->status)->toBe(CorrectionStatus::Rejected)
        ->and(AccessFixtures::violatedRule(fn () => approveCorrection($this, $rejected)))->toBe('correction_decided')
        ->and(KpiActualVersion::query()->where('kpi_actual_id', $actualId)->count())->toBe(1);

    // The requester withdraws a new request; nobody else may.
    $again = $ask('81');

    expect(fn () => $this->corrections->cancel(WorkflowScenario::actor($management), $management, $again->id, $again->lock_version, 'Batal'))->toThrow(AuthorizationException::class)
        ->and($this->corrections->cancel(WorkflowScenario::actor($hr), $hr, $again->id, $again->lock_version, 'Salah aktual')->status)->toBe(CorrectionStatus::Cancelled);

    expect(fn () => DB::transaction(fn () => DB::table('correction_requests')->where('id', $rejected->id)->update(['status' => 'approved'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('correction_requests')->where('id', $rejected->id)->delete()))->toThrow(QueryException::class);
});

test('a pending correction blocks finalizing and locking', function (): void {
    $scenario = $this->scenario;
    $scenario->approveEverything();
    $request = requestCorrection($this);

    expect(AccessFixtures::violatedRule(fn () => $scenario->advance(PeriodStatus::Finalized)))->toBe('period_not_ready');

    $management = $scenario->management;
    $this->corrections->reject(WorkflowScenario::actor($management), $management, $request->id, $request->lock_version, 'Tidak perlu');
    $scenario->advance(PeriodStatus::Finalized);
    requestCorrection($this);

    expect(AccessFixtures::violatedRule(fn () => $scenario->advance(PeriodStatus::Locked)))->toBe('period_not_ready')
        ->and($scenario->period->refresh()->status)->toBe(PeriodStatus::Finalized);
});

test('a failed workflow event in the outbox blocks locking', function (): void {
    $scenario = $this->scenario;
    $scenario->approveEverything();
    $scenario->advance(PeriodStatus::Finalized);

    $message = OutboxMessage::query()->where('event_type', 'FinalApprovalCompleted')->firstOrFail();
    DB::table('outbox_messages')->where('id', $message->id)->update(['failed_at' => now()]);

    expect(AccessFixtures::violatedRule(fn () => $scenario->advance(PeriodStatus::Locked)))->toBe('period_not_ready');

    DB::table('outbox_messages')->where('id', $message->id)->update(['failed_at' => null]);

    expect($scenario->advance(PeriodStatus::Locked)->status)->toBe(PeriodStatus::Locked);
});

test('a locked period changes only through an approved correction, which keeps the period locked and the history intact', function (): void {
    $scenario = $this->scenario;
    $scenario->approveEverything();
    $scenario->advance(PeriodStatus::Finalized);
    $scenario->advance(PeriodStatus::Locked);
    $oldCalculation = $scenario->review('W-001')->scorecard_calculation_id;

    // The normal calculation and the approval actions are closed.
    expect(AccessFixtures::violatedRule(fn () => $scenario->scorecards->calculate()))->toBe('scorecard_final')
        ->and(AccessFixtures::violatedRule(fn () => $scenario->approve($scenario->manager, 'W-001')))->toBe('period_not_in_review');

    $request = approveCorrection($this, requestCorrection($this));
    $review = $scenario->review('W-001');
    $calculation = ScorecardCalculation::query()->findOrFail($review->scorecard_calculation_id);

    expect($request->status)->toBe(CorrectionStatus::Approved)
        ->and($scenario->period->refresh()->status)->toBe(PeriodStatus::Locked)
        ->and($review->state)->toBe(ReviewState::FinalApproved)
        ->and($review->round)->toBe(1)
        ->and($calculation->id)->not->toBe($oldCalculation)
        ->and($calculation->previous_calculation_id)->toBe($oldCalculation)
        ->and($calculation->reason)->toContain($request->public_id)
        ->and(WorkflowService::latestCalculation($review->period_assignment_id)?->id)->toBe($calculation->id)
        ->and(ScorecardReviewDecision::query()->where('scorecard_review_id', $review->id)->where('stage', 'correction')->count())->toBe(1);
});

test('a correction that would leave a scorecard incomplete is refused as a whole', function (): void {
    $scenario = $this->scenario;
    $scenario->approveEverything();
    $scenario->advance(PeriodStatus::Finalized);
    $hr = $scenario->hr();
    $actual = $scenario->actual('SC-QUALITY', 'W-002');

    // W-002 has one quality actual; correcting it to N/A is allowed by the
    // test policy (weights are redistributed), so the scorecard stays complete.
    $request = $this->corrections->request(WorkflowScenario::actor($hr), $hr, $actual->id, null, 'Tidak berlaku bulan ini', 'KPI tidak berlaku');
    $request = approveCorrection($this, $request);

    expect($request->status)->toBe(CorrectionStatus::Approved)
        ->and($scenario->review('W-002')->state)->toBe(ReviewState::FinalApproved);

    // With a policy that holds N/A KPIs, the same kind of correction is refused and nothing changes.
    ScorecardScenario::enablePolicy(['version' => 2, 'not_applicable_weight_rule' => 'block_until_resolved']);
    $other = $scenario->actual('SC-QUALITY', 'W-001');
    $pending = $this->corrections->request(WorkflowScenario::actor($hr), $hr, $other->id, null, 'Tidak berlaku', 'KPI tidak berlaku');

    expect(AccessFixtures::violatedRule(fn () => approveCorrection($this, $pending)))->toBe('correction_breaks_scorecard')
        ->and($pending->refresh()->status)->toBe(CorrectionStatus::Pending)
        ->and(KpiActualVersion::query()->where('kpi_actual_id', $other->id)->count())->toBe(1);
});

test('a final scorecard with an adjusted calibration is not silently overwritten by a correction', function (): void {
    $scenario = $this->scenario;
    $scenario->bypass();
    $scenario->approve($scenario->manager, 'W-001');
    $scenario->calibrate($scenario->hr(), 'W-001', '90', 'Hasil forum kalibrasi');
    $scenario->approve($scenario->management, 'W-001');
    $scenario->approveFully('W-002');
    $scenario->approveFully('PROD-WOVEN');
    $scenario->calibrate($scenario->hr(), 'AR-001');
    $scenario->approve($scenario->management, 'AR-001');
    $scenario->advance(PeriodStatus::Finalized);
    $calculation = $scenario->review('W-001')->scorecard_calculation_id;

    $request = requestCorrection($this);

    expect(AccessFixtures::violatedRule(fn () => approveCorrection($this, $request)))->toBe('correction_needs_recalibration')
        ->and($request->refresh()->status)->toBe(CorrectionStatus::Pending)
        ->and($scenario->review('W-001')->scorecard_calculation_id)->toBe($calculation)
        ->and(KpiActualVersion::query()->where('kpi_actual_id', $request->kpi_actual_id)->count())->toBe(1);

    // A correction that does not touch the calibrated scorecard still applies, and announces the changed final score.
    $other = approveCorrection($this, requestCorrection($this, '70', 'SC-DSO', 'AR-001'));

    expect($other->status)->toBe(CorrectionStatus::Approved)
        ->and(OutboxMessage::query()->where('event_type', 'FinalScoreCorrected')->count())->toBe(1);
});

test('correction actions record the authorizing role, and a stale page decides nothing', function (): void {
    $request = requestCorrection($this);
    $management = $this->scenario->management;

    expect(fn () => $this->corrections->approve(WorkflowScenario::actor($management), $management, $request->id, $request->lock_version + 1))->toThrow(ConcurrencyConflict::class)
        ->and(KpiActualVersion::query()->where('kpi_actual_id', $request->kpi_actual_id)->count())->toBe(1);

    $this->scenario->approve($this->scenario->manager, 'W-001');
    approveCorrection($this, $request);

    $decision = ScorecardReviewDecision::query()->where('stage', 'correction')->firstOrFail();

    expect($decision->actor_role)->toBe('management')
        ->and(AuditLog::query()->where('action', 'correction.approved')->firstOrFail()->after_values['authorized_role'])->toBe('management')
        ->and(AuditLog::query()->where('action', 'correction.requested')->firstOrFail()->after_values['authorized_role'])->toBe('hr_performance');
});

test('the correction queue never shows an account the requests about its own results', function (): void {
    $scenario = $this->scenario;
    $query = app(WorkflowQuery::class);
    requestCorrection($this);

    expect($query->corrections($scenario->hr()))->toHaveCount(1)
        ->and($query->correctionsToDecide($scenario->management))->toHaveCount(1)
        ->and($query->correctionsToDecide($scenario->hr()))->toHaveCount(0)
        ->and($query->corrections($scenario->manager))->toHaveCount(0);

    // The assessed employee later receives a correction role: still nothing about themselves.
    AccessFixtures::grant($scenario->scorecards->staff, Role::Management, 'BAL');
    WorkflowScenario::flushAccess();

    expect($query->corrections($scenario->scorecards->staff))->toHaveCount(0);
});
