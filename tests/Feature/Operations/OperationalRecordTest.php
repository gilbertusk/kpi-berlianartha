<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\RoleCapabilityMatrix;
use App\Modules\Operations\Application\OperationalRecordService;
use App\Modules\Operations\Application\OperationalRecordSource;
use App\Modules\Operations\Application\SourceRecord;
use App\Modules\Operations\Domain\CostingComparison;
use App\Modules\Operations\Infrastructure\Persistence\Models\OperationalRecord;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;
use Tests\Support\ReportHarness;

/*
 * Operational records of the Commercial and Support teams kept as KPI
 * source: Design revision attribution, costing versus actual, team scope,
 * and sensitive figures.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->designA = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-DESIGN-A');
    $this->designB = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-DESIGN-B');
    $this->costing = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-RND-COSTING');
    $this->sales = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-SALES');
    $this->head = AccessFixtures::userWithRole(Role::DepartmentHead, 'COMSUP');
    $this->prodHead = AccessFixtures::userWithRole(Role::DepartmentHead, 'PROD');
    $this->hr = AccessFixtures::userWithRole(Role::HrPerformance, 'BAL');
    $this->management = AccessFixtures::userWithRole(Role::Management, 'BAL');
    $this->auditor = AccessFixtures::userWithRole(Role::Auditor, 'BAL');
    $this->records = app(OperationalRecordService::class);
});

function revision(object $test, User $by, string $unit, string $job, int $number, string $category, string $date = '2026-10-10'): OperationalRecord
{
    return $test->records->record(new Actor($by->id, Actor::system('test')->audit), $by, 'design_revision', $unit, $date, [
        'job_reference' => $job, 'revision_number' => (string) $number, 'category' => $category, 'description' => 'Revisi warna logo',
    ]);
}

function costingRecord(object $test, User $by, string $component, string $estimated, string $actual, string $version = 'V1', string $production = 'WO-100'): OperationalRecord
{
    return $test->records->record(new Actor($by->id, Actor::system('test')->audit), $by, 'costing_comparison', 'COMSUP-RND-COSTING', '2026-10-10', [
        'costing_reference' => 'CST-7', 'costing_version' => $version, 'bom_reference' => 'BOM-7', 'routing_reference' => 'RT-7',
        'production_reference' => $production, 'component' => $component, 'measure_unit' => 'IDR', 'estimated' => $estimated, 'actual' => $actual,
    ]);
}

test('only internal errors are attributed to a Design team, and teams A and B stay apart', function (): void {
    revision($this, $this->designA, 'COMSUP-DESIGN-A', 'ART-1', 1, 'internal_error');
    revision($this, $this->designA, 'COMSUP-DESIGN-A', 'ART-1', 2, 'customer_change');
    revision($this, $this->designA, 'COMSUP-DESIGN-A', 'ART-2', 1, 'sales_change');
    revision($this, $this->designA, 'COMSUP-DESIGN-A', 'ART-3', 1, 'production_issue');
    revision($this, $this->designB, 'COMSUP-DESIGN-B', 'ART-9', 1, 'internal_error');
    revision($this, $this->designB, 'COMSUP-DESIGN-B', 'ART-9', 2, 'internal_error');
    revision($this, $this->designA, 'COMSUP-DESIGN-A', 'ART-OLD', 1, 'internal_error', '2026-09-01');

    $rows = collect(ReportHarness::rows($this->head, 'design_revision_attribution', ['from' => '2026-10-01', 'to' => '2026-10-31']))->keyBy('Team');

    expect($rows->keys()->all())->toBe(['COMSUP-DESIGN-A', 'COMSUP-DESIGN-B'])
        ->and($rows['COMSUP-DESIGN-A']['Total revisi'])->toBe('4')
        ->and($rows['COMSUP-DESIGN-A']['Dibebankan ke team (kesalahan internal)'])->toBe('1')
        ->and($rows['COMSUP-DESIGN-A']['Perubahan customer'])->toBe('1')
        ->and($rows['COMSUP-DESIGN-B']['Dibebankan ke team (kesalahan internal)'])->toBe('2');

    // Each count is traceable to the detail rows with the same filters.
    $detail = collect(ReportHarness::rows($this->head, 'design_revisions', ['from' => '2026-10-01', 'to' => '2026-10-31', 'status' => 'internal_error']));
    expect($detail)->toHaveCount(3)
        ->and($detail->pluck('Kesalahan internal')->unique()->all())->toBe(['ya']);

    // A team leader sees only their own team.
    expect(collect(ReportHarness::rows($this->designA, 'design_revisions'))->pluck('Team')->unique()->all())->toBe(['COMSUP-DESIGN-A'])
        ->and(fn () => revision($this, $this->designA, 'COMSUP-DESIGN-B', 'ART-X', 1, 'internal_error'))->toThrow(AuthorizationException::class)
        ->and(AccessFixtures::violatedRule(fn () => ReportHarness::rows($this->head, 'design_revision_attribution')))->toBe('date_range_required');
});

test('a re-categorised revision is voided and recorded again, and the audit shows both', function (): void {
    $wrong = revision($this, $this->designA, 'COMSUP-DESIGN-A', 'ART-1', 1, 'internal_error');

    expect(AccessFixtures::violatedRule(fn () => revision($this, $this->designA, 'COMSUP-DESIGN-A', 'ART-1', 1, 'customer_change')))->toBe('duplicate_source');

    $this->records->void(new Actor($this->designA->id, Actor::system('test')->audit), $this->designA, $wrong->id, $wrong->lock_version, 'Ternyata permintaan customer');
    revision($this, $this->designA, 'COMSUP-DESIGN-A', 'ART-1', 1, 'customer_change');

    $row = collect(ReportHarness::rows($this->designA, 'design_revision_attribution', ['from' => '2026-10-01', 'to' => '2026-10-31']))->sole();

    expect($row['Dibebankan ke team (kesalahan internal)'])->toBe('0')
        ->and($row['Perubahan customer'])->toBe('1')
        ->and(AuditLog::query()->where('entity_type', 'operational_record')->orderBy('id')->pluck('action')->all())->toBe(['operational_record.recorded', 'operational_record.voided', 'operational_record.recorded'])
        ->and(AccessFixtures::violatedRule(fn () => $this->records->void(new Actor($this->designA->id, Actor::system('test')->audit), $this->designA, $wrong->id, $wrong->refresh()->lock_version, 'Lagi')))->toBe('already_voided');

    // The database refuses edits and deletes, also by direct SQL.
    $live = OperationalRecord::query()->whereNull('voided_at')->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('operational_records')->where('id', $live->id)->update(['details' => '{"category":"internal_error"}'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('operational_records')->where('id', $live->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('operational_records')->where('id', $wrong->id)->update(['void_reason' => 'diubah'])))->toThrow(QueryException::class);
});

test('record fields are validated by the record type', function (array $values, string $type): void {
    $by = $type === 'design_revision' ? $this->designA : $this->costing;
    $unit = $type === 'design_revision' ? 'COMSUP-DESIGN-A' : 'COMSUP-RND-COSTING';

    expect(AccessFixtures::violatedRule(fn () => $this->records->record(new Actor($by->id, Actor::system('test')->audit), $by, $type, $unit, '2026-10-10', $values)))->toBe('invalid_record');
})->with([
    'unknown category' => [['job_reference' => 'A', 'revision_number' => '1', 'category' => 'blame_sales', 'description' => 'x'], 'design_revision'],
    'revision zero' => [['job_reference' => 'A', 'revision_number' => '0', 'category' => 'internal_error', 'description' => 'x'], 'design_revision'],
    'missing description' => [['job_reference' => 'A', 'revision_number' => '1', 'category' => 'internal_error'], 'design_revision'],
    'unknown field' => [['job_reference' => 'A', 'revision_number' => '1', 'category' => 'internal_error', 'description' => 'x', 'salary' => '1'], 'design_revision'],
    'negative cost' => [['costing_reference' => 'C', 'costing_version' => 'V1', 'production_reference' => 'W', 'component' => 'material', 'measure_unit' => 'IDR', 'estimated' => '-1', 'actual' => '1'], 'costing_comparison'],
    'comma decimal' => [['costing_reference' => 'C', 'costing_version' => 'V1', 'production_reference' => 'W', 'component' => 'material', 'measure_unit' => 'IDR', 'estimated' => '1,5', 'actual' => '1'], 'costing_comparison'],
    'unknown component' => [['costing_reference' => 'C', 'costing_version' => 'V1', 'production_reference' => 'W', 'component' => 'overhead', 'measure_unit' => 'IDR', 'estimated' => '1', 'actual' => '1'], 'costing_comparison'],
    'reference with spaces' => [['costing_reference' => 'C 1', 'costing_version' => 'V1', 'production_reference' => 'W', 'component' => 'material', 'measure_unit' => 'IDR', 'estimated' => '1', 'actual' => '1'], 'costing_comparison'],
]);

test('costing is compared exactly per version and never mixes versions', function (): void {
    costingRecord($this, $this->costing, 'material', '1000000', '1150000');
    costingRecord($this, $this->costing, 'labor', '12.5', '10');
    costingRecord($this, $this->costing, 'machine_time', '0', '3');
    costingRecord($this, $this->costing, 'material', '1100000', '1150000', 'V2');

    expect(CostingComparison::of('1000000', '1150000')->variancePercent)->toBe('15.0000')
        ->and(CostingComparison::of('12.5', '10')->variance)->toBe('-2.5000')
        ->and(CostingComparison::of('0', '3')->variancePercent)->toBeNull()
        ->and(CostingComparison::of('3', '1')->variancePercent)->toBe('-66.6667');

    $rows = collect(ReportHarness::rows($this->costing, 'costing_comparisons'));

    expect($rows)->toHaveCount(4)
        ->and($rows->firstWhere('Versi asumsi', 'V2')['Selisih relatif (%)'])->toBe('4.5455')
        ->and($rows->where('Komponen', 'Material')->pluck('Versi asumsi')->all())->toBe(['V1', 'V2'])
        ->and($rows->firstWhere('Komponen', 'Waktu mesin')['Selisih relatif (%)'])->toBe('estimasi nol')
        // Versions are grouped, never interleaved.
        ->and($rows->pluck('Versi asumsi')->all())->toBe(['V1', 'V1', 'V1', 'V2']);

    // The same component of the same costing version and production is one record.
    expect(AccessFixtures::violatedRule(fn () => costingRecord($this, $this->costing, 'material', '1', '2')))->toBe('duplicate_source');

    // A costing record is not a KPI actual: nothing is written to the actuals.
    expect(DB::table('kpi_actuals')->count())->toBe(0);
});

test('cost figures are restricted: HR and other teams cannot read them, and the audit trail does not carry them', function (): void {
    costingRecord($this, $this->costing, 'material', '1000000', '1150000');
    revision($this, $this->designA, 'COMSUP-DESIGN-A', 'ART-1', 1, 'internal_error');

    expect(ReportHarness::rows($this->costing, 'costing_comparisons'))->toHaveCount(1)
        ->and(ReportHarness::rows($this->head, 'costing_comparisons'))->toHaveCount(1)
        ->and(ReportHarness::rows($this->management, 'costing_comparisons'))->toHaveCount(1)
        ->and(ReportHarness::rows($this->auditor, 'costing_comparisons'))->toHaveCount(1)
        // HR reads Design revisions but not cost figures.
        ->and(ReportHarness::rows($this->hr, 'design_revisions'))->toHaveCount(1)
        ->and(fn () => ReportHarness::rows($this->hr, 'costing_comparisons'))->toThrow(AuthorizationException::class)
        // Other teams and departments see nothing of it.
        ->and(ReportHarness::rows($this->designA, 'costing_comparisons'))->toHaveCount(0)
        ->and(ReportHarness::rows($this->prodHead, 'costing_comparisons'))->toHaveCount(0)
        ->and(fn () => ReportHarness::rows($this->sales, 'costing_comparisons', ['unit' => 'COMSUP-RND-COSTING']))->toThrow(AuthorizationException::class);

    $audit = json_encode(AuditLog::query()->where('entity_type', 'operational_record')->get()->pluck('after_values')->all());

    expect($audit)->not->toContain('1150000')
        ->and($audit)->not->toContain('Revisi warna logo');

    // Only team leaders and department heads keep records; read-only and admin roles never do.
    $matrix = new RoleCapabilityMatrix;

    foreach ([Role::Management, Role::HrPerformance, Role::Auditor, Role::SystemAdmin, Role::Employee] as $role) {
        expect($matrix->allows($role, Capability::OperationalRecordManage))->toBeFalse($role->value);
    }

    expect(fn () => costingRecord($this, $this->management, 'labor', '1', '1'))->toThrow(AuthorizationException::class)
        ->and(fn () => costingRecord($this, $this->hr, 'labor', '1', '1'))->toThrow(AuthorizationException::class);
});

test('a source adapter is ingested through the same rules, idempotently, and only for the teams of the importer', function (): void {
    $source = new class implements OperationalRecordSource
    {
        public function key(): string
        {
            return 'design-tracker';
        }

        public function records(): iterable
        {
            yield new SourceRecord('design_revision', 'COMSUP-DESIGN-A', '2026-10-10', ['job_reference' => 'ART-5', 'revision_number' => '1', 'category' => 'customer_change', 'description' => 'Dari tracker']);
            yield new SourceRecord('design_revision', 'COMSUP-DESIGN-A', '2026-10-11', ['job_reference' => 'ART-5', 'revision_number' => '2', 'category' => 'bukan-kategori', 'description' => 'x']);
            yield new SourceRecord('design_revision', 'COMSUP-DESIGN-B', '2026-10-11', ['job_reference' => 'ART-6', 'revision_number' => '1', 'category' => 'internal_error', 'description' => 'Team lain']);
        }
    };

    $actor = new Actor($this->designA->id, Actor::system('test')->audit);

    $rejected = ['Baris 2: Kategori penyebab tidak dikenal.', 'Baris 3: team di luar scope Anda.'];

    expect($this->records->ingest($actor, $this->designA, $source))->toBe(['recorded' => 1, 'skipped' => 0, 'rejected' => $rejected])
        ->and($this->records->ingest($actor, $this->designA, $source))->toBe(['recorded' => 0, 'skipped' => 1, 'rejected' => $rejected])
        ->and(OperationalRecord::query()->sole()->source_system)->toBe('design-tracker');
});

test('a record can point to a KPI definition, and an unknown KPI or a future date is refused', function (): void {
    $kpi = (new KpiFixtures)->publishedDefinition('DSG-INT-REV', 'COMSUP-DESIGN-A');
    $actor = new Actor($this->designA->id, Actor::system('test')->audit);
    $values = ['job_reference' => 'ART-1', 'revision_number' => '1', 'category' => 'internal_error', 'description' => 'x'];

    $record = $this->records->record($actor, $this->designA, 'design_revision', 'COMSUP-DESIGN-A', '2026-10-10', $values, 'dsg-int-rev');

    expect($record->kpi_definition_id)->toBe($kpi->definition->id)
        ->and(collect(ReportHarness::rows($this->designA, 'design_revisions'))->sole()['KPI terkait'])->toBe('DSG-INT-REV')
        ->and(AccessFixtures::violatedRule(fn () => $this->records->record($actor, $this->designA, 'design_revision', 'COMSUP-DESIGN-A', '2026-10-10', [...$values, 'revision_number' => '2'], 'TIDAK-ADA')))->toBe('kpi_not_found')
        ->and(AccessFixtures::violatedRule(fn () => $this->records->record($actor, $this->designA, 'design_revision', 'COMSUP-DESIGN-A', '2026-10-13', [...$values, 'revision_number' => '3'])))->toBe('date_in_future')
        ->and(AccessFixtures::violatedRule(fn () => $this->records->record($actor, $this->designA, 'sales_order', 'COMSUP-DESIGN-A', '2026-10-10', $values)))->toBe('unknown_record_type');
});

test('the page shows each account only the types and teams it may use', function (): void {
    costingRecord($this, $this->costing, 'material', '1000000', '1150000');
    revision($this, $this->designA, 'COMSUP-DESIGN-A', 'ART-1', 1, 'internal_error');
    $employee = AccessFixtures::userWithRole(Role::Employee);

    $this->actingAs($employee)->get(route('operations.index'))->assertForbidden();
    $this->actingAs(AccessFixtures::userWithRole(Role::SystemAdmin))->get(route('operations.index'))->assertForbidden();

    // HR does not get the costing type at all.
    Livewire::actingAs($this->hr)->test('pages::operations.index')
        ->assertSee('Revisi Design')
        ->assertDontSee('Costing vs aktual produksi')
        ->set('type', 'costing_comparison')
        ->assertDontSee('1150000');

    Livewire::actingAs($this->designA)->test('pages::operations.index', ['type' => 'design_revision'])
        ->assertSee('ART-1')
        ->set('recordUnit', 'COMSUP-DESIGN-A')
        ->set('values', ['job_reference' => 'ART-2', 'revision_number' => '1', 'category' => 'sales_change', 'description' => 'Ganti teks'])
        ->call('record')
        ->assertHasNoErrors()
        ->assertSee('ART-2')
        ->set('recordUnit', 'COMSUP-DESIGN-B')
        ->call('record')
        ->assertForbidden();

    // Another team's leader cannot void or even see the record.
    $record = OperationalRecord::query()->where('record_type', 'design_revision')->firstOrFail();
    Livewire::actingAs($this->designB)->test('pages::operations.index', ['type' => 'design_revision'])
        ->assertDontSee('ART-1')
        ->call('startVoid', $record->public_id)
        ->assertNotFound();

    Livewire::actingAs($this->designA)->test('pages::operations.index', ['type' => 'design_revision'])
        ->call('startVoid', $record->public_id)
        ->call('void')
        ->assertHasErrors('action')
        ->set('voidReason', 'Salah kategori')
        ->call('void')
        ->assertHasNoErrors();

    expect($record->refresh()->voided_at)->not->toBeNull();
});

test('teams never block each other, and a typed-in record and an imported copy are the same record', function (): void {
    $sameJob = ['job_reference' => 'ART-1', 'revision_number' => '1', 'category' => 'internal_error', 'description' => 'x'];
    $a = new Actor($this->designA->id, Actor::system('test')->audit);
    $b = new Actor($this->designB->id, Actor::system('test')->audit);

    $this->records->record($a, $this->designA, 'design_revision', 'COMSUP-DESIGN-A', '2026-10-10', $sameJob);
    $this->records->record($b, $this->designB, 'design_revision', 'COMSUP-DESIGN-B', '2026-10-10', $sameJob);

    $source = new class implements OperationalRecordSource
    {
        public function key(): string
        {
            return 'design-tracker';
        }

        public function records(): iterable
        {
            yield new SourceRecord('design_revision', 'COMSUP-DESIGN-A', '2026-10-10', ['job_reference' => 'art-1', 'revision_number' => '1', 'category' => 'internal_error', 'description' => 'x']);
        }
    };

    expect($this->records->ingest($a, $this->designA, $source))->toBe(['recorded' => 0, 'skipped' => 1, 'rejected' => []])
        ->and(OperationalRecord::query()->count())->toBe(2);
});

test('control and invisible characters cannot pass as required text', function (): void {
    $a = new Actor($this->designA->id, Actor::system('test')->audit);

    expect(AccessFixtures::violatedRule(fn () => $this->records->record($a, $this->designA, 'design_revision', 'COMSUP-DESIGN-A', '2026-10-10', ['job_reference' => "\x01\u{200B}", 'revision_number' => '1', 'category' => 'internal_error', 'description' => 'x'])))->toBe('invalid_record');

    $record = $this->records->record($a, $this->designA, 'design_revision', 'COMSUP-DESIGN-A', '2026-10-10', ['job_reference' => "ART\t-7\u{202E}", 'revision_number' => '1', 'category' => 'internal_error', 'description' => "Baris 1\nBaris 2"]);

    expect($record->values()['job_reference'])->toBe('ART-7')
        ->and($record->values()['description'])->toBe("Baris 1\nBaris 2");
});
