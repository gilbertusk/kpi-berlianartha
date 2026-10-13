<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\KpiGovernance\Application\KpiDefinitionService;
use App\Modules\KpiGovernance\Domain\DefinitionContent;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Scoring\Application\ScoreAccess;
use App\Modules\Scoring\Application\ScoringService;
use App\Modules\Scoring\Domain\KpiDataStatus;
use App\Modules\Scoring\Domain\ScorecardStatus;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\ScorecardScenario;
use Tests\Support\ScoringFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    $this->scenario = new ScorecardScenario;
});

function latestCalculation(ScorecardScenario $scenario, string $subjectCode): ScorecardCalculation
{
    return ScorecardCalculation::query()
        ->where('period_assignment_id', $scenario->assignment($subjectCode)->id)
        ->orderByDesc('sequence_number')
        ->with('lines')
        ->firstOrFail();
}

test('official scoring is blocked: the committed configuration has no approved policy (DEC-004 open)', function (): void {
    expect(config('scoring.policies'))->toBe([])
        ->and(AccessFixtures::violatedRule(fn () => $this->scenario->calculate()))->toBe('scoring_policy_not_approved')
        ->and(ScorecardCalculation::query()->count())->toBe(0);
});

test('a calculation stores an immutable snapshot that reconciles to every KPI line', function (): void {
    ScorecardScenario::enablePolicy();
    $this->scenario->standardActuals();

    // Covered subjects: W-001, W-002, AR-001, and the PROD-WOVEN team.
    expect($this->scenario->calculate('Perhitungan awal November'))->toBe(['created' => 4, 'unchanged' => 0, 'complete' => 3, 'incomplete' => 1]);

    $staff = latestCalculation($this->scenario, 'W-001');
    [$quality, $team] = [$staff->lines[0], $staff->lines[1]];

    // 95.5 ÷ 100 × 100 = 95.50 × 60% = 57.3000; 110 ÷ 100 × 100 = 110.00 × 40% = 44.0000; 101.3000 → 101.30.
    expect($staff->status)->toBe(ScorecardStatus::Complete)
        ->and($staff->sequence_number)->toBe(1)
        ->and([$quality->kpi_code, $quality->target_value, $quality->measured_value, $quality->achievement, $quality->kpi_score, $quality->contribution])
        ->toBe(['SC-QUALITY', '100.0000', '95.5', '95.5', '95.5000', '57.3000'])
        ->and([$team->kpi_code, $team->kpi_score, $team->contribution])->toBe(['SC-TEAM', '110.0000', '44.0000'])
        ->and($staff->contribution_total)->toBe('101.3000')
        ->and($staff->final_score)->toBe('101.3000')
        ->and($staff->rating_key)->toBe('r4')
        ->and($staff->policyReference())->toBe('test-only@1')
        ->and($staff->engine_version)->toBe(1)
        ->and($staff->reason)->toBe('Perhitungan awal November')
        ->and($staff->calculated_by_user_id)->toBe($this->scenario->hr()->id)
        ->and($staff->inputs['lines'][0]['entries'][0]['value'])->toBe('95.5000')
        ->and($quality->trace()[0]['decision'])->toBe('used');

    $sum = BigDecimal::zero();
    foreach ($staff->lines as $line) {
        $sum = $sum->plus((string) $line->contribution);
    }
    expect($sum->isEqualTo((string) $staff->contribution_total))->toBeTrue();

    // The team KPI is one target: the same score on the member and the team scorecard.
    $teamCard = latestCalculation($this->scenario, 'PROD-WOVEN');
    expect($teamCard->lines[0]->period_target_id)->toBe($team->period_target_id)
        ->and($teamCard->final_score)->toBe('110.0000');

    // W-002 has no quality actual: held by the KPI rule, no final score, no rating.
    $missing = latestCalculation($this->scenario, 'W-002');
    expect($missing->status)->toBe(ScorecardStatus::Incomplete)
        ->and($missing->blockers)->toBe(['kpi_blocked'])
        ->and($missing->lines[0]->data_status)->toBe(KpiDataStatus::MissingBlocked)
        ->and($missing->lines[0]->kpi_score)->toBeNull()
        ->and($missing->final_score)->toBeNull()
        ->and($missing->rating_key)->toBeNull();

    expect(AuditLog::query()->where('action', 'scorecard.calculated')->count())->toBe(4)
        ->and(AuditLog::query()->where('action', 'scorecard.calculation_run')->value('reason'))->toBe('Perhitungan awal November')
        ->and(OutboxMessage::query()->where('event_type', 'ScoreCalculated')->count())->toBe(4)
        // The event announces the calculation without carrying the score.
        ->and(OutboxMessage::query()->where('event_type', 'ScoreCalculated')->first()?->payload)->not->toHaveKey('final_score');
});

test('recalculation is idempotent and adds a new snapshot only for subjects whose input changed', function (): void {
    ScorecardScenario::enablePolicy();
    $this->scenario->standardActuals();
    $this->scenario->calculate();
    $first = latestCalculation($this->scenario, 'W-002');

    expect($this->scenario->calculate('Ulang tanpa perubahan'))->toBe(['created' => 0, 'unchanged' => 4, 'complete' => 3, 'incomplete' => 1])
        ->and(ScorecardCalculation::query()->count())->toBe(4);

    // A draft is not eligible data: the line waits for verification.
    $this->scenario->enter($this->scenario->target('SC-QUALITY', 'W-002'), '90');
    expect($this->scenario->calculate('Draft W-002 masuk'))->toMatchArray(['created' => 1, 'unchanged' => 3])
        ->and(latestCalculation($this->scenario, 'W-002')->lines[0]->data_status)->toBe(KpiDataStatus::PendingVerification);

    $this->scenario->submitAndVerify();
    $this->scenario->calculate('Aktual W-002 terverifikasi');
    $latest = latestCalculation($this->scenario, 'W-002');

    // 90 × 60% + 110 × 40% = 54 + 44 = 98.00.
    expect($latest->sequence_number)->toBe(3)
        ->and($latest->previous_calculation_id)->not->toBeNull()
        ->and($latest->final_score)->toBe('98.0000')
        ->and($latest->rating_key)->toBe('r3')
        // The first snapshot is untouched.
        ->and(ScorecardCalculation::query()->find($first->id)?->final_score)->toBeNull()
        ->and(latestCalculation($this->scenario, 'W-001')->sequence_number)->toBe(1);
});

test('the database refuses any change to a stored calculation', function (string $table): void {
    ScorecardScenario::enablePolicy();
    $this->scenario->standardActuals();
    $this->scenario->calculate();
    $id = DB::table($table)->value('id');

    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update(['updated_at' => now()])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))->toThrow(QueryException::class);
})->with(['scorecard_calculations', 'scorecard_calculation_lines']);

test('only periods in progress or in review are scored; a final period needs the correction workflow', function (): void {
    ScorecardScenario::enablePolicy();
    $this->scenario->standardActuals();

    $open = $this->scenario->fixtures->opened(['code' => 'P-2026-12', 'name' => 'Desember 2026', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);
    expect(AccessFixtures::violatedRule(fn () => app(ScoringService::class)->calculate($this->scenario->hrActor(), $open->id, ScoreAccess::everything(), 'uji')))->toBe('period_not_scorable');

    DB::table('assessment_periods')->where('id', $this->scenario->period->id)->update(['status' => 'review']);
    expect($this->scenario->calculate()['created'])->toBe(4);

    DB::table('assessment_periods')->where('id', $this->scenario->period->id)->update(['status' => 'finalized']);
    expect(AccessFixtures::violatedRule(fn () => $this->scenario->calculate()))->toBe('scorecard_final');
});

test('a reason is required for every calculation', function (): void {
    ScorecardScenario::enablePolicy();

    expect(AccessFixtures::violatedRule(fn () => $this->scenario->calculate('   ')))->toBe('reason_required');
});

test('a policy version keeps its content once used, and closing it with an end date is allowed', function (): void {
    ScorecardScenario::enablePolicy();
    $this->scenario->standardActuals();
    $this->scenario->calculate();

    ScorecardScenario::enablePolicy(['effective_until' => '2027-01-01']);
    expect($this->scenario->calculate()['unchanged'])->toBe(4);

    ScorecardScenario::enablePolicy(['final_rounding' => ['mode' => 'down', 'scale' => 0]]);
    expect(AccessFixtures::violatedRule(fn () => $this->scenario->calculate()))->toBe('scoring_policy_changed');
});

test('an invalid policy configuration stops scoring instead of being skipped', function (array $policies): void {
    Config::set('scoring.policies', $policies);

    expect(AccessFixtures::violatedRule(fn () => $this->scenario->calculate()))->toBe('scoring_policy_invalid');
})->with([
    'missing rule' => [[array_diff_key(ScoringFixtures::policyConfig(), ['late_data_rule' => true])]],
    'overlapping versions' => [[ScoringFixtures::policyConfig(), ScoringFixtures::policyConfig(['version' => 2, 'effective_from' => '2026-11-15'])]],
    'duplicate version' => [[ScoringFixtures::policyConfig(['effective_until' => '2026-10-20']), ScoringFixtures::policyConfig(['effective_from' => '2026-10-20'])]],
]);

test('a calculation scope limits the subjects calculated', function (): void {
    ScorecardScenario::enablePolicy();
    $this->scenario->standardActuals();
    $hrProd = AccessFixtures::userWithRole(Role::HrPerformance, 'PROD');

    $summary = $this->scenario->calculate('HR produksi', ScoreAccess::forCalculating(app(AccessControl::class), $hrProd));

    expect($summary['created'])->toBe(3)
        ->and(ScorecardCalculation::query()->where('period_assignment_id', $this->scenario->assignment('AR-001')->id)->exists())->toBeFalse();
});

test('an old period reproduces its scorecards after new KPI and policy versions are published', function (): void {
    ScorecardScenario::enablePolicy(['effective_until' => '2026-12-01']);
    $this->scenario->standardActuals();
    $this->scenario->calculate();
    $before = latestCalculation($this->scenario, 'W-001');

    // New rules from December: KPI v2 with a higher cap, policy v2 with other bands.
    $service = app(KpiDefinitionService::class);
    $kpi = $this->scenario->fixtures->kpi;
    $draft = $service->startNewVersion($kpi->authorActor(), $this->scenario->quality->kpi_definition_id, 'Cap baru');
    $draft = $service->updateDraft($kpi->authorActor(), $draft->id, $draft->lock_version, [
        ...$draft->only(array_keys(DefinitionContent::FIELDS)),
        'score_parameters' => ScoringFixtures::ratioParameters(['score_cap' => '150']),
        'signoff_reference' => 'TEST-SIGNOFF-V2',
        'effective_from' => '2026-12-01',
    ]);
    $kpi->submitAndPublish($draft);

    Config::set('scoring.policies', [
        ScoringFixtures::policyConfig(['effective_until' => '2026-12-01']),
        ScoringFixtures::policyConfig(['version' => 2, 'effective_from' => '2026-12-01', 'rating_bands' => [['key' => 'all', 'label' => 'Semua', 'min' => null, 'max' => null]]]),
    ]);

    expect($this->scenario->calculate('Setelah aturan baru')['unchanged'])->toBe(4);

    $after = latestCalculation($this->scenario, 'W-001');
    expect($after->id)->toBe($before->id)
        ->and($after->lines[0]->details['score'])->toBe('95.5000')
        ->and($after->inputs['lines'][0]['parameters']['score_cap'])->toBe('120')
        ->and($after->policyReference())->toBe('test-only@1')
        ->and(app(ScoringService::class)->policyFor(new AssessmentPeriod(['assignment_reference_date' => '2026-12-01']))->reference())->toBe('test-only@2');
});
