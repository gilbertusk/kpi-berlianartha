<?php

declare(strict_types=1);

use App\Modules\ActualEvidence\Application\ActualAccess;
use App\Modules\ActualEvidence\Application\ActualEntry;
use App\Modules\ActualEvidence\Application\ActualImportService;
use App\Modules\ActualEvidence\Application\ActualService;
use App\Modules\ActualEvidence\Application\EvidenceService;
use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\ActualImportBatch;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\EvidenceFile;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;
use Tests\Support\PeriodFixtures;

/*
 * Direct URL and forged Livewire action tests for actuals and evidence.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    Storage::fake('s3');
    Config::set('evidence.scanner', 'local-unscanned');

    $this->fixtures = new PeriodFixtures(new KpiFixtures);
    $this->hr = $this->fixtures->kpi->author;

    $this->staff = AccessFixtures::user('Staff Woven');
    AccessFixtures::employee($this->staff, 'PROD-WOVEN', number: 'W-001');
    AccessFixtures::grant($this->staff, Role::Employee);
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-002');
    AccessFixtures::employee(null, 'ACC-AR', number: 'AR-001');

    $this->users = [
        'management' => AccessFixtures::userWithRole(Role::Management, 'BAL'),
        'department_head' => AccessFixtures::userWithRole(Role::DepartmentHead, 'PROD'),
        'team_leader' => AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN'),
        'employee' => $this->staff,
        'hr_performance' => $this->hr,
        'auditor' => AccessFixtures::userWithRole(Role::Auditor, 'BAL'),
        'system_admin' => AccessFixtures::userWithRole(Role::SystemAdmin),
        // An employee whose home team is not part of any target in the period.
        'outsider' => AccessFixtures::userWithRole(Role::Employee),
    ];

    $quality = $this->fixtures->kpi('AP-QUALITY', 'PROD-WOVEN', ['decimal_places' => 2]);
    $dso = $this->fixtures->kpi('AP-DSO', 'ACC-AR', ['evidence_requirement' => 'none', 'evidence_description' => '']);
    $this->fixtures->publishedTemplate('T-AP-WOVEN', 'PROD-WOVEN', [$quality->id => '100']);
    $this->fixtures->publishedTemplate('T-AP-AR', 'ACC-AR', [$dso->id => '100']);
    $this->period = $this->fixtures->inProgress();

    $this->own = $this->fixtures->target($this->period, 'AP-QUALITY', 'W-001');
    $this->colleague = $this->fixtures->target($this->period, 'AP-QUALITY', 'W-002');
    $this->pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
});

test('actual pages allow exactly the accounts that have something to see in the period', function (string $roleKey, int $status): void {
    $this->actingAs($this->users[$roleKey])->get(route('actuals.index'))->assertStatus($roleKey === 'system_admin' ? 403 : 200);
    $this->actingAs($this->users[$roleKey])->get(route('actuals.entry', $this->period))->assertStatus($status);
})->with([
    ['management', 200],
    ['department_head', 200],
    ['team_leader', 200],
    ['employee', 200],
    ['hr_performance', 200],
    ['auditor', 200],
    ['system_admin', 403],
    ['outsider', 403],
]);

test('only accounts that enter actuals for units reach the import page', function (string $roleKey, int $status): void {
    $this->actingAs($this->users[$roleKey])->get(route('actuals.import', $this->period))->assertStatus($status);
})->with([
    ['hr_performance', 200],
    ['employee', 403],
    ['team_leader', 403],
    ['management', 403],
    ['auditor', 403],
    ['system_admin', 403],
]);

test('an employee sees and enters only their own actuals', function (): void {
    $this->actingAs($this->staff)->get(route('actuals.index'))->assertOk()->assertSee($this->period->code);

    Livewire::actingAs($this->staff)->test('pages::actuals.entry', ['period' => $this->period])
        ->assertSee('W-001')
        ->assertDontSee('W-002')
        ->assertDontSee('AR-001')
        ->assertDontSee('Verifikasi aktual yang diajukan')
        ->set("values.{$this->own->public_id}", '96.555')
        ->call('saveDrafts')
        ->assertHasErrors('action')
        ->assertSee('KPI ini menerima paling banyak 2 angka di belakang koma.')
        ->set("values.{$this->own->public_id}", '96.5')
        // A forged key for a colleague's target is ignored: it is not a row of this page.
        ->set("values.{$this->colleague->public_id}", '10')
        ->call('saveDrafts')
        ->assertHasNoErrors();

    expect(KpiActual::query()->pluck('period_target_id')->all())->toBe([$this->own->id])
        ->and(AuditLog::query()->where('action', 'actual.draft_created')->value('actor_role'))->toBe('employee');

    Livewire::actingAs($this->staff)->test('pages::actuals.entry', ['period' => $this->period])
        ->call('showDetail', $this->colleague->public_id)
        ->assertForbidden();
});

test('an employee attaches evidence, submits, and the team leader verifies and downloads it', function (): void {
    app(ActualService::class)->saveDrafts(new Actor($this->staff->id, Actor::system('test')->audit), $this->period->id, ActualAccess::everything(), [
        new ActualEntry('a', $this->own->id, '2026-11-01', '2026-11-30', '96.5', 'percent'),
    ]);
    $actual = KpiActual::query()->firstOrFail();

    Livewire::actingAs($this->staff)->test('pages::actuals.entry', ['period' => $this->period])
        ->call('submitDrafts')
        ->assertHasErrors('action')
        ->call('showDetail', $this->own->public_id)
        ->call('startAttach', $actual->public_id)
        ->set('evidenceFile', UploadedFile::fake()->createWithContent('virus.pdf', "MZ\x90\x00 not a pdf"))
        ->call('attachEvidence')
        ->assertHasErrors('evidence')
        ->call('startAttach', $actual->public_id)
        ->set('evidenceFile', UploadedFile::fake()->createWithContent('laporan qc.pdf', $this->pdf))
        ->call('attachEvidence')
        ->assertHasNoErrors()
        ->assertSee('laporan qc.pdf')
        ->call('submitDrafts')
        ->assertHasNoErrors();

    $evidence = EvidenceFile::query()->firstOrFail();

    expect(EvidenceFile::query()->count())->toBe(1)
        ->and(KpiActualVersion::query()->value('status'))->toBe(ActualStatus::Submitted);

    $leader = $this->users['team_leader'];

    $page = Livewire::actingAs($leader)->test('pages::actuals.entry', ['period' => $this->period])
        ->assertDontSee('Simpan draft halaman ini')
        ->call('showDetail', $this->own->public_id)
        ->call('openEvidence', $evidence->public_id);

    $link = $page->effects['redirect'] ?? null;

    expect($link)->toBeString()->toContain('signature=');

    $this->actingAs($leader)->get($link)->assertOk();
    $this->actingAs($this->users['management'])->get($link)->assertForbidden();

    Livewire::actingAs($leader)->test('pages::actuals.entry', ['period' => $this->period])
        ->call('verifySubmitted')
        ->assertHasNoErrors();

    expect(KpiActualVersion::query()->value('status'))->toBe(ActualStatus::Verified)
        ->and(AuditLog::query()->where('action', 'actual.verified')->value('actor_role'))->toBe('team_leader')
        ->and(AuditLog::query()->where('action', 'evidence.downloaded')->count())->toBe(1);
});

test('a team leader rejects with a reason and cannot enter values', function (): void {
    $actuals = app(ActualService::class);
    $target = $this->fixtures->target($this->period, 'AP-DSO', 'AR-001');
    $actuals->saveDrafts($this->fixtures->kpi->authorActor(), $this->period->id, ActualAccess::everything(), [new ActualEntry('a', $target->id, '2026-11-01', '2026-11-30', '45', 'percent')]);
    $actuals->submit($this->fixtures->kpi->authorActor(), $this->period->id, ActualAccess::everything());
    $actual = KpiActual::query()->firstOrFail();
    $leaderAr = AccessFixtures::userWithRole(Role::TeamLeader, 'ACC-AR');

    // The Woven leader has no authority over Accounting.
    Livewire::actingAs($this->users['team_leader'])->test('pages::actuals.entry', ['period' => $this->period])
        ->call('startReject', $actual->public_id)
        ->assertForbidden();

    Livewire::actingAs($leaderAr)->test('pages::actuals.entry', ['period' => $this->period])
        ->set("values.{$target->public_id}", '1')
        ->call('saveDrafts')
        ->assertForbidden();

    Livewire::actingAs($leaderAr)->test('pages::actuals.entry', ['period' => $this->period])
        ->call('startReject', $actual->public_id)
        ->call('confirmReject')
        ->assertHasErrors('rejectReason')
        ->set('rejectReason', 'Tidak cocok dengan aging report')
        ->call('confirmReject')
        ->assertHasNoErrors();

    expect(KpiActualVersion::query()->value('status'))->toBe(ActualStatus::Rejected);
});

test('read-only roles cannot change actuals or evidence through forged actions', function (string $roleKey): void {
    app(ActualService::class)->saveDrafts($this->fixtures->kpi->authorActor(), $this->period->id, ActualAccess::everything(), [
        new ActualEntry('a', $this->own->id, '2026-11-01', '2026-11-30', '96.5', 'percent'),
    ]);
    $actual = KpiActual::query()->firstOrFail();
    $evidence = app(EvidenceService::class)->attach($this->fixtures->kpi->authorActor(), $actual->id, 'laporan.pdf', $this->pdf);
    $user = $this->users[$roleKey];
    $page = fn () => Livewire::actingAs($user)->test('pages::actuals.entry', ['period' => $this->period]);

    $page()->set("values.{$this->own->public_id}", '1')->call('saveDrafts')->assertForbidden();
    $page()->call('submitDrafts')->assertForbidden();
    $page()->call('verifySubmitted')->assertForbidden();
    $page()->call('startAttach', $actual->public_id)->assertForbidden();
    $page()->call('removeEvidence', $evidence->public_id)->assertForbidden();
    $page()->call('startReject', $actual->public_id)->assertForbidden();

    expect(KpiActualVersion::query()->value('actual_value'))->toBe('96.5000')
        ->and($evidence->fresh()?->removed_at)->toBeNull();
})->with(['auditor', 'management', 'department_head']);

test('a file is staged, shown with row results, committed, and its errors can be downloaded', function (): void {
    $header = 'kpi_code,subject_type,subject_code,window_start,window_end,unit,actual_value';
    $valid = "{$header}\nAP-QUALITY,employee,W-001,2026-11-01,2026-11-30,percent,96.5\nAP-DSO,employee,AR-001,2026-11-01,2026-11-30,percent,0\n";
    $invalid = "{$header}\nAP-QUALITY,employee,W-001,2026-11-01,2026-11-30,percent,96.555\nAP-DSO,employee,AR-001,2026-11-01,2026-11-30,percent,45\n";

    Livewire::actingAs($this->hr)->test('pages::actuals.import', ['period' => $this->period])
        ->set('file', UploadedFile::fake()->createWithContent('salah.csv', $invalid))
        ->call('stage')
        ->assertHasNoErrors()
        ->assertSee('File tidak dapat di-commit')
        ->assertSee('Unduh baris error (CSV)')
        ->call('commit')
        ->assertHasErrors('action');

    $failed = ActualImportBatch::query()->firstOrFail();
    $download = $this->actingAs($this->hr)->get(route('actuals.import.errors', [$this->period, $failed]));

    $download->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($download->streamedContent())->toContain('row_number,kpi_code', 'AP-QUALITY', 'paling banyak 2 angka')
        ->and($download->streamedContent())->not->toContain('AP-DSO')
        ->and(KpiActual::query()->count())->toBe(0);

    $this->actingAs($this->users['auditor'])->get(route('actuals.import.errors', [$this->period, $failed]))->assertForbidden();

    Livewire::actingAs($this->hr)->test('pages::actuals.import', ['period' => $this->period])
        ->set('file', UploadedFile::fake()->createWithContent('aktual.csv', $valid))
        ->call('stage')
        ->assertHasNoErrors()
        ->assertSee('Seluruh baris valid. 2 aktual akan disimpan sebagai draft.')
        ->call('commit')
        ->assertHasNoErrors()
        ->assertSee('Rekonsiliasi cocok');

    expect(KpiActualVersion::query()->orderBy('id')->pluck('actual_value')->all())->toBe(['96.5000', '0.0000']);

    Livewire::actingAs($this->hr)->test('pages::actuals.import', ['period' => $this->period])
        ->set('file', UploadedFile::fake()->create('aktual.exe', 5))
        ->call('stage')
        ->assertHasErrors('file');
});

test('an import batch is visible only to its uploader or to HR for the whole period', function (): void {
    $hrProduction = AccessFixtures::userWithRole(Role::HrPerformance, 'PROD');
    $path = tempnam(sys_get_temp_dir(), 'kpi-');
    file_put_contents($path, "kpi_code,subject_type,subject_code,window_start,window_end,unit,actual_value\nAP-DSO,employee,AR-001,2026-11-01,2026-11-30,percent,45\n");
    $batch = app(ActualImportService::class)->stage($this->fixtures->kpi->authorActor(), $this->period, ActualAccess::everything(), 'semua-unit.csv', $path);
    @unlink($path);

    Livewire::actingAs($hrProduction)->test('pages::actuals.import', ['period' => $this->period])
        ->assertOk()
        ->assertDontSee('semua-unit.csv')
        ->call('show', $batch->public_id)
        ->assertNotFound();

    $this->actingAs($hrProduction)->get(route('actuals.import.errors', [$this->period, $batch]))->assertNotFound();

    Livewire::actingAs($this->fixtures->kpi->reviewer)->test('pages::actuals.import', ['period' => $this->period])
        ->assertSee('semua-unit.csv');
});

test('the navigation shows actuals to employees and not to system admins', function (): void {
    $this->actingAs($this->staff)->get(route('dashboard'))->assertOk()->assertSee(route('actuals.index'), false);
    $this->actingAs($this->users['system_admin'])->get(route('dashboard'))->assertOk()->assertDontSee(route('actuals.index'), false);
});

test('an invalid observation window in the URL falls back to the period and typed values survive a search', function (): void {
    $this->actingAs($this->hr)->get(route('actuals.entry', ['period' => $this->period, 'dari' => 'abc', 'sampai' => '2026-02-30']))
        ->assertOk()
        ->assertSee('AP-QUALITY');

    Livewire::actingAs($this->hr)->test('pages::actuals.entry', ['period' => $this->period])
        ->set('windowStart', '')
        ->assertOk()
        ->set('windowStart', '2026-11-01')
        ->set("values.{$this->own->public_id}", '91')
        ->set('search', 'W-001')
        ->assertSet("values.{$this->own->public_id}", '91')
        ->set('windowEnd', '2026-11-15')
        ->assertSet('values', []);
});

test('a value typed for an entry that was submitted meanwhile is reported, not silently dropped', function (): void {
    $actuals = app(ActualService::class);
    $actor = $this->fixtures->kpi->authorActor();
    $target = $this->fixtures->target($this->period, 'AP-DSO', 'AR-001');
    $actuals->saveDrafts($actor, $this->period->id, ActualAccess::everything(), [new ActualEntry('a', $target->id, '2026-11-01', '2026-11-30', '45', 'percent')]);

    $page = Livewire::actingAs($this->hr)->test('pages::actuals.entry', ['period' => $this->period]);
    $actuals->submit($actor, $this->period->id, ActualAccess::everything());

    $page->set("values.{$target->public_id}", '50')
        ->call('saveDrafts')
        ->assertHasErrors('action')
        ->assertSee('Nilai yang Anda ketik tidak disimpan');

    expect(KpiActualVersion::query()->value('actual_value'))->toBe('45.0000');
});
