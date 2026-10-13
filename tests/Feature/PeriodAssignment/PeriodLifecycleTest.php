<?php

declare(strict_types=1);

use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\DeadlineStage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Shared\Domain\Exceptions\ConcurrencyConflict;
use App\Modules\Shared\Domain\Exceptions\InvalidStateTransition;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;
use Tests\Support\PeriodFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->fixtures = new PeriodFixtures(new KpiFixtures);
    $this->periods = app(PeriodService::class);
    $this->actor = $this->fixtures->kpi->authorActor();
});

test('HR creates a draft period with its own timezone, reference date, and stage deadlines', function (): void {
    $period = $this->fixtures->draft(['deadlines' => [
        DeadlineStage::TargetSetting->value => '2026-11-05',
        DeadlineStage::FinalApproval->value => '2026-12-10',
        DeadlineStage::Review->value => '',
    ]]);

    expect($period->status)->toBe(PeriodStatus::Draft)
        ->and($period->timezone)->toBe('Asia/Jakarta')
        ->and($period->assignment_reference_date)->toBe('2026-11-01')
        ->and($period->deadlineMap())->toBe(['target_setting' => '2026-11-05', 'final_approval' => '2026-12-10'])
        ->and(AuditLog::query()->where('action', 'assessment_period.created')->where('entity_id', $period->public_id)->exists())->toBeTrue();
});

test('period input is validated', function (array $overrides, string $rule): void {
    expect(AccessFixtures::violatedRule(fn () => $this->fixtures->draft($overrides)))->toBe($rule);
})->with([
    'end before start' => [['endsOn' => '2026-10-31'], 'invalid_period'],
    'impossible date' => [['startsOn' => '2026-11-31'], 'invalid_period'],
    'reference date outside the period' => [['referenceDate' => '2026-12-01'], 'invalid_reference_date'],
    'unknown type' => [['type' => 'weekly'], 'invalid_period_type'],
    'invalid code' => [['code' => 'p 11'], 'invalid_code'],
    'unknown deadline stage' => [['deadlines' => ['lock' => '2026-12-01']], 'invalid_deadline_stage'],
    'later stage closes before an earlier one' => [['deadlines' => ['target_setting' => '2026-11-10', 'verification' => '2026-11-05']], 'invalid_deadlines'],
]);

test('a duplicate period and an overlapping period of the same type and scope are rejected', function (): void {
    $this->fixtures->draft();

    expect(AccessFixtures::violatedRule(fn () => $this->fixtures->draft(['code' => 'P-DUP'])))->toBe('period_duplicate')
        ->and(AccessFixtures::violatedRule(fn () => $this->fixtures->draft(['code' => 'P-2026-11'])))->toBe('period_duplicate')
        ->and(AccessFixtures::violatedRule(fn () => $this->fixtures->draft(['code' => 'P-OVERLAP', 'startsOn' => '2026-11-15', 'endsOn' => '2026-12-14'])))->toBe('period_overlap')
        // A department inside the company scope is the same population.
        ->and(AccessFixtures::violatedRule(fn () => $this->fixtures->draft(['code' => 'P-PROD', 'unit' => 'PROD'])))->toBe('period_overlap');

    // Adjacent dates, another type, or unrelated scopes do not overlap.
    $december = $this->fixtures->draft(['code' => 'P-2026-12', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);
    $quarter = $this->fixtures->draft(['code' => 'P-2026-Q4', 'type' => 'quarterly', 'startsOn' => '2026-10-01', 'endsOn' => '2026-12-31']);

    expect($december->exists)->toBeTrue()->and($quarter->exists)->toBeTrue();
});

test('periods for unrelated branches may run on the same dates', function (): void {
    $production = $this->fixtures->draft(['code' => 'P-PROD-11', 'unit' => 'PROD']);
    $accounting = $this->fixtures->draft(['code' => 'P-ACC-11', 'unit' => 'ACC']);

    expect($production->organization_unit_id)->not->toBe($accounting->organization_unit_id)
        ->and(AccessFixtures::violatedRule(fn () => $this->fixtures->draft(['code' => 'P-WOVEN-11', 'unit' => 'PROD-WOVEN'])))->toBe('period_overlap');
});

test('a period code is unique', function (): void {
    $this->fixtures->draft();

    expect(AccessFixtures::violatedRule(fn () => $this->fixtures->draft(['startsOn' => '2026-12-01', 'endsOn' => '2026-12-31'])))->toBe('period_code_taken');
});

test('a draft is edited with optimistic locking and only while it is a draft', function (): void {
    $period = $this->fixtures->draft();
    $stale = $period->lock_version;

    $updated = $this->periods->updateDraft($this->actor, $period->id, $stale, PeriodFixtures::setup(['name' => 'November 2026 (revisi)', 'deadlines' => ['target_setting' => '2026-11-07']]));

    expect($updated->name)->toBe('November 2026 (revisi)')
        ->and($updated->deadlineMap())->toBe(['target_setting' => '2026-11-07'])
        ->and(fn () => $this->periods->updateDraft($this->actor, $period->id, $stale, PeriodFixtures::setup(['name' => 'Basi'])))->toThrow(ConcurrencyConflict::class)
        ->and(AccessFixtures::violatedRule(fn () => $this->periods->updateDraft($this->actor, $period->id, $updated->lock_version, PeriodFixtures::setup(['name' => 'November 2026 (revisi)', 'deadlines' => ['target_setting' => '2026-11-07']]))))->toBe('no_change');
});

test('opening needs at least one subject with a template in force', function (): void {
    AccessFixtures::employee(null, 'PROD-WOVEN');
    $period = $this->fixtures->draft();

    expect(AccessFixtures::violatedRule(fn () => $this->periods->open($this->actor, $period->id, $period->lock_version)))->toBe('no_covered_assignment')
        ->and($period->fresh()?->status)->toBe(PeriodStatus::Draft)
        ->and(PeriodAssignment::query()->count())->toBe(0);
});

test('opening stores the snapshot, publishes PeriodOpened, and freezes the period identity', function (): void {
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-001');
    $kpi = $this->fixtures->kpi('LC-OUTPUT');
    $this->fixtures->publishedTemplate('T-LC-WOVEN', 'PROD-WOVEN', [$kpi->id => '100']);

    $period = $this->fixtures->opened();

    expect($period->status)->toBe(PeriodStatus::Open)
        ->and($period->opened_by_user_id)->toBe($this->fixtures->kpi->author->id)
        ->and(PeriodAssignment::query()->where('assessment_period_id', $period->id)->where('subject_code', 'W-001')->value('coverage'))->toBe(Coverage::Covered)
        ->and(OutboxMessage::query()->where('event_type', 'PeriodOpened')->where('aggregate_id', $period->public_id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'assessment_period.opened')->where('entity_id', $period->public_id)->value('after_values'))->toMatchArray(['status' => 'open', 'covered' => 1])
        ->and(AccessFixtures::violatedRule(fn () => $this->periods->updateDraft($this->actor, $period->id, $period->lock_version, PeriodFixtures::setup(['name' => 'Diubah']))))->toBe('period_not_editable');

    expect(fn () => DB::transaction(fn () => DB::table('assessment_periods')->where('id', $period->id)->update(['ends_on' => '2026-12-31'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('assessment_periods')->where('id', $period->id)->delete()))->toThrow(QueryException::class);
});

test('a period cannot be opened twice and a stale open creates no second snapshot', function (): void {
    AccessFixtures::employee(null, 'PROD-WOVEN');
    $kpi = $this->fixtures->kpi('LC-TWICE');
    $this->fixtures->publishedTemplate('T-LC-TWICE', 'PROD-WOVEN', [$kpi->id => '100']);

    $draft = $this->fixtures->draft();
    $this->periods->open($this->actor, $draft->id, $draft->lock_version);
    $rows = PeriodAssignment::query()->count();

    expect(fn () => $this->periods->open($this->actor, $draft->id, $draft->lock_version))->toThrow(InvalidStateTransition::class)
        ->and(PeriodAssignment::query()->count())->toBe($rows);
});

test('a second overlapping draft cannot be opened once the first one is open', function (): void {
    AccessFixtures::employee(null, 'PROD-WOVEN');
    $kpi = $this->fixtures->kpi('LC-RACE');
    $this->fixtures->publishedTemplate('T-LC-RACE', 'PROD-WOVEN', [$kpi->id => '100']);

    $first = $this->fixtures->draft();

    // Simulates two drafts created at the same moment, before either check saw the other.
    $secondId = DB::table('assessment_periods')->insertGetId([
        'public_id' => '01KTESTPERIOD0000000000000',
        'code' => 'P-RACE',
        'name' => 'Race',
        'period_type' => 'monthly',
        'starts_on' => '2026-11-10',
        'ends_on' => '2026-12-09',
        'timezone' => 'Asia/Jakarta',
        'organization_unit_id' => AccessFixtures::unit('PROD')->id,
        'assignment_reference_date' => '2026-11-10',
        'status' => 'draft',
        'lock_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->periods->open($this->actor, $first->id, $first->lock_version);

    expect(AccessFixtures::violatedRule(fn () => $this->periods->open($this->actor, $secondId, 1)))->toBe('period_overlap');
});

test('the lifecycle cannot be skipped, even through direct SQL', function (): void {
    $period = $this->fixtures->draft();

    expect(fn () => DB::transaction(fn () => DB::table('assessment_periods')->where('id', $period->id)->update(['status' => 'locked'])))->toThrow(QueryException::class)
        ->and(fn () => $this->periods->startProgress($this->actor, $period->id, $period->lock_version))->toThrow(InvalidStateTransition::class)
        ->and(fn () => DB::transaction(fn () => DB::table('assessment_periods')->insert([
            'public_id' => '01KTESTPERIOD0000000000001',
            'code' => 'P-FORGED',
            'name' => 'Forged',
            'period_type' => 'annual',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'timezone' => 'Asia/Jakarta',
            'organization_unit_id' => AccessFixtures::unit('BAL')->id,
            'assignment_reference_date' => '2027-01-01',
            'status' => 'open',
            'lock_version' => 1,
        ])))->toThrow(QueryException::class)
        ->and(PeriodStatus::transitions()['locked'])->toBe([]);
});

test('progress starts only when every required target is final', function (): void {
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-010');
    $kpi = $this->fixtures->kpi('LC-PROGRESS');
    $this->fixtures->publishedTemplate('T-LC-PROGRESS', 'PROD-WOVEN', [$kpi->id => '100']);
    $period = $this->fixtures->opened();

    expect(AccessFixtures::violatedRule(fn () => $this->periods->startProgress($this->actor, $period->id, $period->lock_version)))->toBe('period_not_ready')
        ->and($this->periods->blockersFor($period, PeriodStatus::InProgress))->toBe(['1 target wajib belum final.']);

    $this->fixtures->approvedTarget($period, $this->fixtures->target($period, 'LC-PROGRESS', 'W-010'), '95');

    $started = $this->periods->startProgress($this->actor, $period->id, $period->fresh()->lock_version);

    expect($started->status)->toBe(PeriodStatus::InProgress)
        ->and($started->progress_started_by_user_id)->toBe($this->fixtures->kpi->author->id);
});

test('a deadline of an opened period moves only with a reason, and the order still holds', function (): void {
    AccessFixtures::employee(null, 'PROD-WOVEN');
    $kpi = $this->fixtures->kpi('LC-DEADLINE');
    $this->fixtures->publishedTemplate('T-LC-DEADLINE', 'PROD-WOVEN', [$kpi->id => '100']);
    $period = $this->fixtures->opened(['deadlines' => ['target_setting' => '2026-11-05', 'actual_submission' => '2026-12-03']]);

    expect(AccessFixtures::violatedRule(fn () => $this->periods->rescheduleDeadline($this->actor, $period->id, $period->lock_version, DeadlineStage::TargetSetting, '2026-11-08', ' ')))->toBe('reason_required')
        ->and(AccessFixtures::violatedRule(fn () => $this->periods->rescheduleDeadline($this->actor, $period->id, $period->lock_version, DeadlineStage::TargetSetting, '2026-12-05', 'Terlalu jauh')))->toBe('invalid_deadlines');

    $moved = $this->periods->rescheduleDeadline($this->actor, $period->id, $period->lock_version, DeadlineStage::TargetSetting, '2026-11-08', 'Libur nasional');
    $entry = AuditLog::query()->where('action', 'assessment_period.deadline_rescheduled')->where('entity_id', $period->public_id)->firstOrFail();

    expect($moved->deadlineFor(DeadlineStage::TargetSetting))->toBe('2026-11-08')
        ->and($entry->reason)->toBe('Libur nasional')
        ->and($entry->before_values)->toMatchArray(['due_on' => '2026-11-05'])
        ->and(AssessmentPeriod::query()->findOrFail($period->id)->lock_version)->toBe($period->lock_version + 1);

    $draft = $this->fixtures->draft(['code' => 'P-2026-12', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);

    expect(AccessFixtures::violatedRule(fn () => $this->periods->rescheduleDeadline($this->actor, $draft->id, $draft->lock_version, DeadlineStage::TargetSetting, '2026-12-05', 'Draft')))->toBe('deadline_not_reschedulable');
});
