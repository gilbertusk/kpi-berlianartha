<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Target\Application\TargetImportService;
use App\Modules\Target\Application\TargetService;
use App\Modules\Target\Domain\TargetStatus;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTargetVersion;
use App\Modules\Target\Infrastructure\Persistence\Models\TargetImportBatch;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;
use Tests\Support\PeriodFixtures;

/*
 * Direct URL and forged Livewire action tests for periods and targets: every
 * page and every action authorizes on the server, and lists are filtered by
 * the viewer's unit scope.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->fixtures = new PeriodFixtures(new KpiFixtures);
    $this->hr = $this->fixtures->kpi->author;
    $this->secondHr = $this->fixtures->kpi->reviewer;

    $this->users = [
        'management' => AccessFixtures::userWithRole(Role::Management, 'BAL'),
        'department_head' => AccessFixtures::userWithRole(Role::DepartmentHead, 'PROD'),
        'team_leader' => AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN'),
        'employee' => AccessFixtures::userWithRole(Role::Employee),
        'hr_performance' => $this->hr,
        'auditor' => AccessFixtures::userWithRole(Role::Auditor, 'BAL'),
        'system_admin' => AccessFixtures::userWithRole(Role::SystemAdmin),
    ];

    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-001');
    AccessFixtures::employee(null, 'ACC-AR', number: 'AR-001');

    $quality = $this->fixtures->kpi('PG-QUALITY', 'PROD-WOVEN', ['decimal_places' => 2]);
    $dso = $this->fixtures->kpi('PG-DSO', 'ACC-AR');
    $this->fixtures->publishedTemplate('T-PG-WOVEN', 'PROD-WOVEN', [$quality->id => '100']);
    $this->fixtures->publishedTemplate('T-PG-AR', 'ACC-AR', [$dso->id => '100']);

    $this->period = $this->fixtures->opened();
});

test('period and target pages allow exactly the roles that may read them', function (string $roleKey, int $status): void {
    $user = $this->users[$roleKey];

    $this->actingAs($user)->get(route('periods.index'))->assertStatus($status);
    $this->actingAs($user)->get(route('periods.show', $this->period))->assertStatus($status);
    $this->actingAs($user)->get(route('periods.targets', $this->period))->assertStatus($status);
})->with([
    ['management', 200],
    ['department_head', 200],
    ['team_leader', 200],
    ['hr_performance', 200],
    ['auditor', 200],
    ['employee', 403],
    ['system_admin', 403],
]);

test('only HR Performance reaches the import page and the fill-in file', function (string $roleKey, int $status): void {
    $user = $this->users[$roleKey];

    $this->actingAs($user)->get(route('periods.targets.import', $this->period))->assertStatus($status);
    $this->actingAs($user)->get(route('periods.targets.template', $this->period))->assertStatus($status);
})->with([
    ['hr_performance', 200],
    ['management', 403],
    ['department_head', 403],
    ['team_leader', 403],
    ['auditor', 403],
    ['employee', 403],
    ['system_admin', 403],
]);

test('the navigation shows periods only to accounts that may open them', function (): void {
    $this->actingAs($this->hr)->get(route('dashboard'))->assertOk()->assertSee('Periode &amp; Target', false);
    $this->actingAs($this->users['system_admin'])->get(route('dashboard'))->assertOk()->assertDontSee('Periode &amp; Target', false);
});

test('a period outside the viewer scope is not listed and cannot be opened by URL', function (): void {
    $accounting = $this->fixtures->draft(['code' => 'P-ACC-12', 'unit' => 'ACC', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);

    $this->actingAs($this->users['team_leader'])->get(route('periods.index'))
        ->assertOk()
        ->assertSee('P-2026-11')
        ->assertDontSee('P-ACC-12');

    $this->actingAs($this->users['team_leader'])->get(route('periods.show', $accounting))->assertForbidden();
    $this->actingAs($this->users['department_head'])->get(route('periods.targets', $accounting))->assertForbidden();
    $this->actingAs($this->users['auditor'])->get(route('periods.show', $accounting))->assertOk();
});

test('inside a period a reader sees only assignments and targets of their own scope', function (): void {
    $leader = $this->users['team_leader'];

    $this->actingAs($leader)->get(route('periods.show', $this->period))
        ->assertOk()
        ->assertSee('W-001')
        ->assertDontSee('AR-001');

    $this->actingAs($leader)->get(route('periods.targets', $this->period))
        ->assertOk()
        ->assertSee('PG-QUALITY')
        ->assertDontSee('PG-DSO')
        ->assertDontSee('Simpan draft halaman ini')
        ->assertDontSee('Setujui target yang diajukan');
});

test('read-only roles cannot change periods or targets through forged actions', function (string $roleKey): void {
    $user = $this->users[$roleKey];
    $draft = $this->fixtures->draft(['code' => 'P-2026-12', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);
    $target = $this->fixtures->target($this->period, 'PG-QUALITY', 'W-001');

    Livewire::actingAs($user)->test('pages::periods.index')
        ->set('newCode', 'FORGED')
        ->set('newName', 'Forged')
        ->set('newStartsOn', '2027-01-01')
        ->set('newEndsOn', '2027-01-31')
        ->set('newUnit', AccessFixtures::unit('PROD-WOVEN')->public_id)
        ->call('create')
        ->assertForbidden();

    Livewire::actingAs($user)->test('pages::periods.show', ['period' => $draft])->call('open')->assertForbidden();
    Livewire::actingAs($user)->test('pages::periods.show', ['period' => $draft])->set('name', 'Forged')->call('saveDraft')->assertForbidden();
    Livewire::actingAs($user)->test('pages::periods.show', ['period' => $this->period])
        ->set('rescheduleStage', 'target_setting')
        ->set('rescheduleReason', 'forged')
        ->call('reschedule')
        ->assertForbidden();
    Livewire::actingAs($user)->test('pages::periods.show', ['period' => $this->period])->call('startProgress')->assertForbidden();

    Livewire::actingAs($user)->test('pages::periods.targets', ['period' => $this->period])
        ->set("values.{$target->public_id}", '99')
        ->call('saveDrafts')
        ->assertForbidden();
    Livewire::actingAs($user)->test('pages::periods.targets', ['period' => $this->period])->call('submitDrafts')->assertForbidden();
    Livewire::actingAs($user)->test('pages::periods.targets', ['period' => $this->period])->call('approveSubmitted')->assertForbidden();
    Livewire::actingAs($user)->test('pages::periods.targets', ['period' => $this->period])->call('startRevision', $target->public_id)->assertForbidden();
    Livewire::actingAs($user)->test('pages::periods.targets', ['period' => $this->period])->call('startReturn', $target->public_id)->assertForbidden();

    expect(AssessmentPeriod::query()->where('code', 'FORGED')->exists())->toBeFalse()
        ->and($draft->fresh()?->status)->toBe(PeriodStatus::Draft)
        ->and(PeriodTargetVersion::query()->count())->toBe(0);
})->with(['auditor', 'management', 'department_head', 'team_leader']);

test('department-scoped HR manages periods and targets only inside its department', function (): void {
    $hrAccounting = AccessFixtures::userWithRole(Role::HrPerformance, 'ACC');
    $woven = $this->fixtures->target($this->period, 'PG-QUALITY', 'W-001');
    $accounting = $this->fixtures->target($this->period, 'PG-DSO', 'AR-001');

    $create = fn (string $unitCode, string $code) => Livewire::actingAs($hrAccounting)->test('pages::periods.index')
        ->set('newCode', $code)
        ->set('newName', 'Desember')
        ->set('newStartsOn', '2026-12-01')
        ->set('newEndsOn', '2026-12-31')
        ->set('newUnit', AccessFixtures::unit($unitCode)->public_id)
        ->call('create');

    $create('BAL', 'P-BAL-12')->assertForbidden();
    $create('PROD', 'P-PROD-12')->assertForbidden();
    $create('ACC', 'P-ACC-12')->assertHasNoErrors()->assertRedirect();

    // The company period concerns Accounting, but only HR for the whole scope may run it.
    Livewire::actingAs($hrAccounting)->test('pages::periods.show', ['period' => $this->period])->call('startProgress')->assertForbidden();

    Livewire::actingAs($hrAccounting)->test('pages::periods.targets', ['period' => $this->period])
        ->assertSee('PG-DSO')
        ->assertDontSee('PG-QUALITY')
        ->set("values.{$accounting->public_id}", '45')
        ->set("values.{$woven->public_id}", '99')
        ->call('saveDrafts')
        ->assertHasNoErrors();

    expect(AssessmentPeriod::query()->pluck('code')->all())->toContain('P-ACC-12')
        ->and(AssessmentPeriod::query()->pluck('code')->all())->not->toContain('P-BAL-12', 'P-PROD-12')
        ->and(PeriodTargetVersion::query()->pluck('period_target_id')->all())->toBe([$accounting->id]);
});

test('HR sets up a period, previews who is assessed by whom, and opens it', function (): void {
    AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-QC');
    AccessFixtures::employee(null, 'PROD-QC', number: 'Q-001');

    Livewire::actingAs($this->hr)->test('pages::periods.index')
        ->set('newCode', '2026-12')
        ->set('newName', 'Desember 2026')
        ->set('newType', 'monthly')
        ->set('newStartsOn', '2026-12-01')
        ->set('newEndsOn', '2026-12-31')
        ->set('newUnit', AccessFixtures::unit('BAL')->public_id)
        ->set('newDeadlines.target_setting', '2026-12-05')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect();

    $period = AssessmentPeriod::query()->where('code', '2026-12')->firstOrFail();

    Livewire::actingAs($this->hr)->test('pages::periods.show', ['period' => $period])
        ->assertSee('Pratinjau penugasan (belum disimpan)')
        ->set('search', 'W-001')
        ->assertSee('T-PG-WOVEN v1')
        ->assertSee($this->users['team_leader']->name.' (PROD-WOVEN)')
        ->assertSee($this->users['department_head']->name.' (PROD)')
        ->assertDontSee('AR-001')
        ->set('search', 'Q-001')
        ->assertSee('Tanpa template yang berlaku')
        // Accounting has no Manager/Head: the gap is shown, not filled.
        ->set('search', 'AR-001')
        ->assertSee('T-PG-AR v1')
        ->assertSee('Belum dikonfigurasi')
        ->set('search', '')
        ->set('deadlines.target_setting', '2026-12-07')
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->call('open')
        ->assertHasNoErrors()
        ->assertSee('Snapshot penugasan')
        ->assertSee('Kelengkapan target')
        ->assertDontSee('Pratinjau penugasan (belum disimpan)');

    expect($period->fresh()?->status)->toBe(PeriodStatus::Open)
        ->and($period->fresh()?->deadlineMap())->toBe(['target_setting' => '2026-12-07'])
        ->and(AuditLog::query()->where('action', 'assessment_period.opened')->where('entity_id', $period->public_id)->value('actor_role'))->toBe('hr_performance');
});

test('a stale period page cannot change a period that someone else changed', function (): void {
    $draft = $this->fixtures->draft(['code' => 'P-2026-12', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);
    $page = Livewire::actingAs($this->hr)->test('pages::periods.show', ['period' => $draft]);

    Livewire::actingAs($this->secondHr)->test('pages::periods.show', ['period' => $draft])->set('name', 'Diubah HR kedua')->call('saveDraft')->assertHasNoErrors();

    $page->set('name', 'Versi basi')->call('saveDraft')->assertHasErrors('action');

    expect($draft->fresh()?->name)->toBe('Diubah HR kedua');
});

test('targets go from the matrix through submit to approval by a second HR account', function (): void {
    $target = $this->fixtures->target($this->period, 'PG-QUALITY', 'W-001');

    Livewire::actingAs($this->hr)->test('pages::periods.targets', ['period' => $this->period])
        ->assertSee('0 dari 2 target wajib sudah final.')
        ->set("values.{$target->public_id}", '97.555')
        ->call('saveDrafts')
        ->assertHasErrors('action')
        ->assertSee('KPI ini menerima paling banyak 2 angka di belakang koma.')
        ->set("values.{$target->public_id}", '97.5')
        ->call('saveDrafts')
        ->assertHasNoErrors()
        ->call('submitDrafts')
        ->assertHasNoErrors()
        ->call('approveSubmitted')
        ->assertHasErrors('action');

    expect(PeriodTargetVersion::query()->where('period_target_id', $target->id)->value('status'))->toBe(TargetStatus::Submitted);

    Livewire::actingAs($this->secondHr)->test('pages::periods.targets', ['period' => $this->period])
        ->call('approveSubmitted')
        ->assertHasNoErrors()
        ->assertSee('1 dari 2 target wajib sudah final.')
        ->call('startRevision', $target->public_id)
        ->set('reviseValue', '95')
        ->call('saveRevision')
        ->assertHasErrors('reviseReason')
        ->set('reviseReason', 'Kapasitas mesin turun')
        ->call('saveRevision')
        ->assertHasNoErrors()
        ->call('showHistory', $target->public_id)
        ->assertSee('Kapasitas mesin turun')
        ->assertSee('target.revision_drafted');

    $versions = PeriodTargetVersion::query()->where('period_target_id', $target->id)->orderBy('version_number')->get();

    expect($versions->pluck('status')->all())->toBe([TargetStatus::Approved, TargetStatus::Draft])
        ->and($versions[0]->target_value)->toBe('97.5000')
        ->and(AuditLog::query()->where('action', 'target.approved')->where('entity_id', $target->public_id)->value('actor_role'))->toBe('hr_performance');
});

test('a submitted target can be returned with a reason from the matrix', function (): void {
    $target = $this->fixtures->target($this->period, 'PG-QUALITY', 'W-001');

    Livewire::actingAs($this->hr)->test('pages::periods.targets', ['period' => $this->period])
        ->set("values.{$target->public_id}", '97')
        ->call('saveDrafts')
        ->call('submitDrafts')
        ->assertHasNoErrors();

    Livewire::actingAs($this->secondHr)->test('pages::periods.targets', ['period' => $this->period])
        ->call('startReturn', $target->public_id)
        ->call('confirmReturn')
        ->assertHasErrors('returnReason')
        ->set('returnReason', 'Belum sesuai notulen')
        ->call('confirmReturn')
        ->assertHasNoErrors();

    expect(PeriodTargetVersion::query()->where('period_target_id', $target->id)->value('status'))->toBe(TargetStatus::Draft);
});

test('an uploaded file is staged, shown with its row results, and committed from the import page', function (): void {
    $valid = "kpi_code,subject_type,subject_code,unit,target_value\nPG-QUALITY,employee,W-001,percent,97.5\nPG-DSO,employee,AR-001,percent,45\n";
    $invalid = "kpi_code,subject_type,subject_code,unit,target_value\nPG-QUALITY,employee,W-001,percent,97.555\nPG-DSO,employee,AR-001,percent,45\n";

    Livewire::actingAs($this->hr)->test('pages::periods.import', ['period' => $this->period])
        ->set('file', UploadedFile::fake()->createWithContent('salah.csv', $invalid))
        ->call('stage')
        ->assertHasNoErrors()
        ->assertSee('File tidak dapat di-commit')
        ->assertSee('KPI ini menerima paling banyak 2 angka di belakang koma.')
        ->call('commit')
        ->assertHasErrors('action');

    expect(PeriodTargetVersion::query()->count())->toBe(0);

    Livewire::actingAs($this->hr)->test('pages::periods.import', ['period' => $this->period])
        ->set('file', UploadedFile::fake()->createWithContent('target.csv', $valid))
        ->call('stage')
        ->assertHasNoErrors()
        ->assertSee('Seluruh baris valid. 2 target akan disimpan sebagai draft.')
        ->call('commit')
        ->assertHasNoErrors()
        ->assertSee('Sudah di-commit');

    expect(PeriodTargetVersion::query()->where('source', 'import')->count())->toBe(2)
        ->and(TargetImportBatch::query()->where('status', TargetImportBatch::STATUS_COMMITTED)->count())->toBe(1);

    Livewire::actingAs($this->hr)->test('pages::periods.import', ['period' => $this->period])
        ->set('file', UploadedFile::fake()->create('target.xlsx', 10))
        ->call('stage')
        ->assertHasErrors('file');
});

test('the fill-in file contains only targets inside the downloader scope', function (): void {
    $hrAccounting = AccessFixtures::userWithRole(Role::HrPerformance, 'ACC');

    $all = $this->actingAs($this->hr)->get(route('periods.targets.template', $this->period));
    $scoped = $this->actingAs($hrAccounting)->get(route('periods.targets.template', $this->period));

    $all->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($all->streamedContent())->toContain('kpi_code,kpi_name,subject_type,subject_code,subject_name,unit,target_value,source_note', 'PG-QUALITY', 'PG-DSO')
        ->and($scoped->streamedContent())->toContain('PG-DSO')
        ->and($scoped->streamedContent())->not->toContain('PG-QUALITY');
});

test('an import batch is visible only to its uploader or to HR for the whole period', function (): void {
    $hrAccounting = AccessFixtures::userWithRole(Role::HrPerformance, 'ACC');
    $contents = "kpi_code,subject_type,subject_code,unit,target_value\nPG-QUALITY,employee,W-001,percent,97.5\n";
    $batch = app(TargetImportService::class)->stage($this->fixtures->kpi->authorActor(), $this->period, PeriodFixtures::everything(), 'semua-unit.csv', $contents);

    // HR for Accounting manages targets in this period, but this batch holds
    // rows of other units.
    Livewire::actingAs($hrAccounting)->test('pages::periods.import', ['period' => $this->period])
        ->assertOk()
        ->assertDontSee('semua-unit.csv')
        ->call('show', $batch->public_id)
        ->assertNotFound();

    Livewire::actingAs($this->secondHr)->test('pages::periods.import', ['period' => $this->period])
        ->assertSee('semua-unit.csv')
        ->call('show', $batch->public_id)
        ->assertSee('PG-QUALITY');
});

test('a stale matrix cannot overwrite a draft that someone else changed or created', function (): void {
    $target = $this->fixtures->target($this->period, 'PG-QUALITY', 'W-001');
    $other = $this->fixtures->target($this->period, 'PG-DSO', 'AR-001');
    $targets = app(TargetService::class);
    $targets->saveDrafts($this->fixtures->kpi->authorActor(), $this->period->id, PeriodFixtures::everything(), [$this->fixtures->entry($target, '90')]);

    $page = Livewire::actingAs($this->hr)->test('pages::periods.targets', ['period' => $this->period]);

    $draft = PeriodTargetVersion::query()->where('period_target_id', $target->id)->firstOrFail();
    $targets->saveDrafts($this->fixtures->kpi->reviewerActor(), $this->period->id, PeriodFixtures::everything(), [
        $this->fixtures->entry($target, '95', expectedLock: $draft->lock_version),
        $this->fixtures->entry($other, '40'),
    ]);

    $page->set("values.{$other->public_id}", '45')->call('saveDrafts')->assertHasErrors('action')->assertSee('baru saja diubah pengguna lain');

    expect($draft->fresh()?->target_value)->toBe('95.0000')
        ->and(PeriodTargetVersion::query()->where('period_target_id', $other->id)->value('target_value'))->toBe('40.0000');
});
