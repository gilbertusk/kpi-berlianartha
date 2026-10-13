<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\Workflow\Domain\CorrectionStatus;
use App\Modules\Workflow\Domain\ReviewState;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ApprovalStageBypass;
use App\Modules\Workflow\Infrastructure\Persistence\Models\CorrectionRequest;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardCalibration;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\WorkflowScenario;

/*
 * Direct URL and forged Livewire action tests for the approval workflow.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    $this->scenario = new WorkflowScenario;
    $this->period = $this->scenario->period;

    $this->users = [
        'management' => $this->scenario->management,
        'department_head' => $this->scenario->manager,
        'team_leader' => $this->scenario->scorecards->leader,
        'employee' => $this->scenario->scorecards->staff,
        'hr_performance' => $this->scenario->hr(),
        'auditor' => AccessFixtures::userWithRole(Role::Auditor, 'BAL'),
        'system_admin' => AccessFixtures::userWithRole(Role::SystemAdmin),
        'other_head' => AccessFixtures::userWithRole(Role::DepartmentHead, 'COMSUP'),
    ];

    $this->reviewUrl = fn (string $subject): string => route('approvals.review', $this->scenario->review($subject));
});

test('approval pages open for exactly the accounts with a part in the workflow', function (string $roleKey, int $index, int $period, int $review, int $bypasses): void {
    $user = $this->users[$roleKey];

    $this->actingAs($user)->get(route('approvals.index'))->assertStatus($index);
    $this->actingAs($user)->get(route('approvals.corrections'))->assertStatus($index);
    $this->actingAs($user)->get(route('approvals.period', $this->period))->assertStatus($period);
    $this->actingAs($user)->get(($this->reviewUrl)('W-001'))->assertStatus($review);
    $this->actingAs($user)->get(route('approvals.bypasses'))->assertStatus($bypasses);
})->with([
    'management' => ['management', 200, 200, 200, 403],
    'department head (reviewer)' => ['department_head', 200, 200, 200, 403],
    // A team leader verifies actuals but holds no stage of the scorecard approval.
    'team leader' => ['team_leader', 200, 200, 403, 403],
    // The assessed employee does not read the approval notes about themselves.
    'employee W-001' => ['employee', 403, 403, 403, 403],
    'hr' => ['hr_performance', 200, 200, 200, 200],
    'auditor (read-only)' => ['auditor', 200, 200, 200, 403],
    'system admin' => ['system_admin', 403, 403, 403, 403],
    'head of another department' => ['other_head', 200, 200, 403, 403],
]);

test('the approval menu is shown only to accounts with a part in the workflow', function (): void {
    $this->actingAs($this->users['department_head'])->get(route('dashboard'))->assertSee(route('approvals.index'), escape: false);
    $this->actingAs($this->users['employee'])->get(route('dashboard'))->assertDontSee(route('approvals.index'), escape: false);
});

test('the reviewer approves from the scorecard page and a return needs a reason', function (): void {
    $review = $this->scenario->review('W-001');

    Livewire::actingAs($this->users['department_head'])->test('pages::approvals.review', ['review' => $review])
        ->assertSee('Keputusan Anda: Review')
        ->call('sendBack')
        ->assertHasErrors('action')
        ->set('comment', 'Sesuai')
        ->call('approve')
        ->assertHasNoErrors()
        ->assertSee('Menunggu kalibrasi')
        ->assertDontSee('Keputusan Anda');

    expect($this->scenario->review('W-001')->state)->toBe(ReviewState::Reviewed);
});

test('an account that can read the review cannot decide a stage it does not hold', function (string $roleKey): void {
    $review = $this->scenario->review('W-001');

    $page = Livewire::actingAs($this->users[$roleKey])->test('pages::approvals.review', ['review' => $review])
        ->assertOk()
        ->assertDontSee('Keputusan Anda');

    $page->call('approve')->assertForbidden();

    Livewire::actingAs($this->users[$roleKey])->test('pages::approvals.review', ['review' => $review])
        ->set('comment', 'Paksa')
        ->call('sendBack')
        ->assertForbidden();

    expect($this->scenario->review('W-001')->state)->toBe(ReviewState::InReview);
})->with(['hr_performance', 'management', 'auditor']);

test('accounts outside the workflow cannot open a review to act on it', function (string $roleKey): void {
    Livewire::actingAs($this->users[$roleKey])->test('pages::approvals.review', ['review' => $this->scenario->review('W-001')])->assertForbidden();
})->with(['employee', 'team_leader', 'other_head', 'system_admin']);

test('HR calibrates on the page and the calculated score stays visible next to the calibrated one', function (): void {
    $this->scenario->approve($this->scenario->manager, 'W-001');
    $review = $this->scenario->review('W-001');

    Livewire::actingAs($this->users['hr_performance'])->test('pages::approvals.review', ['review' => $review])
        ->assertSee('Kalibrasi')
        ->set('calibratedScore', '90')
        ->call('calibrate')
        ->assertHasErrors('action')
        ->set('calibrationReason', 'Hasil forum kalibrasi')
        ->call('calibrate')
        ->assertHasNoErrors()
        ->assertSee('90.0000')
        ->assertSee($review->calculation->final_score)
        ->assertSee('Hasil forum kalibrasi');

    expect(ScorecardCalibration::query()->where('scorecard_review_id', $review->id)->firstOrFail()->is_adjusted)->toBeTrue();
});

test('a stale page cannot decide twice', function (): void {
    $review = $this->scenario->review('W-001');
    $page = Livewire::actingAs($this->users['department_head'])->test('pages::approvals.review', ['review' => $review]);

    // The same reviewer already decided in another tab.
    $this->scenario->approve($this->scenario->manager, 'W-001');

    $page->call('approve')->assertHasErrors('action');

    expect($this->scenario->review('W-001')->decisions()->count())->toBe(1);
});

test('bulk approval from the inbox ignores scorecards that are not in the account inbox', function (): void {
    $ids = fn (array $subjects): array => array_map(fn (string $subject): string => $this->scenario->review($subject)->public_id, $subjects);

    Livewire::actingAs($this->users['department_head'])->test('pages::approvals.index')
        ->assertSee('Menunggu keputusan Anda (3)')
        ->set('selected', $ids(['W-001', 'W-002', 'AR-001']))
        ->set('comment', 'Disetujui bersama')
        ->call('approveSelected')
        ->assertHasNoErrors()
        ->assertSee('2 scorecard disetujui')
        ->assertSee('Menunggu keputusan Anda (1)');

    expect($this->scenario->review('W-001')->state)->toBe(ReviewState::Reviewed)
        ->and($this->scenario->review('W-002')->state)->toBe(ReviewState::Reviewed)
        ->and($this->scenario->review('AR-001')->state)->toBe(ReviewState::InReview);

    Livewire::actingAs($this->users['hr_performance'])->test('pages::approvals.index')
        ->set('selected', $ids(['PROD-WOVEN']))
        ->call('approveSelected')
        ->assertHasErrors('action');

    expect($this->scenario->review('PROD-WOVEN')->state)->toBe(ReviewState::InReview);
});

test('the period board shows the lock checklist and only period managers advance the period', function (): void {
    Livewire::actingAs($this->users['hr_performance'])->test('pages::approvals.period', ['period' => $this->period])
        ->assertSee('Checklist menuju Finalized')
        ->assertSee('4 scorecard belum mendapat approval final.')
        ->assertSee('Review: Belum dikonfigurasi')
        ->call('advance')
        ->assertHasErrors('action');

    Livewire::actingAs($this->users['management'])->test('pages::approvals.period', ['period' => $this->period])
        ->assertDontSee('Lanjutkan ke')
        ->call('advance')
        ->assertForbidden();

    $this->scenario->approveEverything();

    Livewire::actingAs($this->users['hr_performance'])->test('pages::approvals.period', ['period' => $this->period])
        ->assertSee('Semua pemeriksaan lulus')
        ->call('advance')
        ->assertHasNoErrors()
        ->assertSee('Checklist menuju Locked')
        ->call('advance')
        ->assertHasNoErrors()
        ->assertSee('Periode sudah dikunci.');

    expect($this->period->refresh()->status)->toBe(PeriodStatus::Locked);
});

test('a department head sees only the scorecards of their own scope on the board', function (): void {
    Livewire::actingAs($this->users['department_head'])->test('pages::approvals.period', ['period' => $this->period])
        ->assertSee('W-001')
        ->assertSee('PROD-WOVEN')
        ->assertDontSee('AR-001');

    Livewire::actingAs($this->users['other_head'])->test('pages::approvals.period', ['period' => $this->period])
        ->assertDontSee('W-001')
        ->assertDontSee('AR-001');
});

test('a correction is requested by HR on the scorecard page and decided by Management in the queue', function (): void {
    $review = $this->scenario->review('W-001');
    $actual = $this->scenario->actual('SC-QUALITY', 'W-001');

    // Management reads the review but cannot request a correction.
    Livewire::actingAs($this->users['management'])->test('pages::approvals.review', ['review' => $review])
        ->assertDontSee('Minta koreksi aktual')
        ->set('correctionActual', $actual->public_id)
        ->set('correctionValue', '80')
        ->set('correctionReason', 'Paksa')
        ->call('requestCorrection')
        ->assertStatus(422);

    Livewire::actingAs($this->users['hr_performance'])->test('pages::approvals.review', ['review' => $review])
        ->assertSee('Minta koreksi aktual')
        ->set('correctionActual', $actual->public_id)
        ->set('correctionValue', '80')
        ->call('requestCorrection')
        ->assertHasErrors('correction')
        ->set('correctionReason', 'Salah input laporan mutu')
        ->call('requestCorrection')
        ->assertHasNoErrors();

    $request = CorrectionRequest::query()->firstOrFail();

    // HR sees its request but cannot approve it.
    Livewire::actingAs($this->users['hr_performance'])->test('pages::approvals.corrections')
        ->assertSee('Salah input laporan mutu')
        ->call('open', $request->public_id)
        ->call('approve')
        ->assertForbidden();

    // An account outside the correction roles cannot even open it.
    Livewire::actingAs($this->users['department_head'])->test('pages::approvals.corrections')
        ->assertDontSee('Salah input laporan mutu')
        ->call('open', $request->public_id)
        ->assertForbidden();

    Livewire::actingAs($this->users['management'])->test('pages::approvals.corrections')
        ->assertSee('Salah input laporan mutu')
        ->call('open', $request->public_id)
        ->call('reject')
        ->assertHasErrors('action')
        ->call('approve')
        ->assertHasNoErrors();

    expect($request->refresh()->status)->toBe(CorrectionStatus::Approved)
        ->and($this->scenario->review('W-001')->round)->toBe(1)
        ->and($this->scenario->review('W-001')->scorecard_calculation_id)->not->toBe($review->scorecard_calculation_id);
});

test('HR registers and revokes a review bypass; other roles cannot', function (): void {
    Livewire::actingAs($this->users['management'])->test('pages::approvals.bypasses')->assertForbidden();

    Livewire::actingAs($this->users['hr_performance'])->test('pages::approvals.bypasses')
        ->set('unit', 'ACC-AR')
        ->call('register')
        ->assertHasErrors('action')
        ->set('signoffReference', 'MEMO-TEST-01')
        ->set('reason', 'Team tanpa manager')
        ->call('register')
        ->assertHasNoErrors()
        ->assertSee('MEMO-TEST-01')
        ->set('unit', 'TIDAK-ADA')
        ->call('register')
        ->assertStatus(422);

    $bypass = ApprovalStageBypass::query()->firstOrFail();

    Livewire::actingAs($this->users['hr_performance'])->test('pages::approvals.bypasses')
        ->call('startRevoke', $bypass->public_id)
        ->call('revoke')
        ->assertHasErrors('action')
        ->set('revokeReason', 'Manager sudah ditunjuk')
        ->call('revoke')
        ->assertHasNoErrors()
        ->assertSee('Dicabut');

    expect($bypass->refresh()->revoked_at)->not->toBeNull();
});
