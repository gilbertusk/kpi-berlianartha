<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Domain\PpicStatus;
use App\Modules\Production\Infrastructure\Persistence\Models\PpicStatusChange;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\ProductionHarness as Harness;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    Harness::useCalendar();
    $this->woven = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    $this->flexo = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-FLEXO');
    $this->ppic = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-ADMIN-PPIC');
    $this->hr = AccessFixtures::userWithRole(Role::HrPerformance, 'BAL');
    $this->sales = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-SALES');
    $this->arLeader = AccessFixtures::userWithRole(Role::TeamLeader, 'ACC-AR');
    $this->travelTo(CarbonImmutable::parse(Harness::NOW, 'UTC'));
});

test('the pages open only for production, PPIC, and company-wide readers', function (): void {
    foreach ([$this->sales, $this->arLeader] as $outsider) {
        $this->actingAs($outsider)->get(route('production.index'))->assertForbidden();
        $this->actingAs($outsider)->get(route('ppic.index'))->assertForbidden();
    }

    $this->actingAs($this->hr)->get(route('production.index'))->assertOk()->assertDontSee('Impor batch');
    $this->actingAs($this->woven)->get(route('ppic.index'))->assertOk()->assertSee('Belum ada data work order');
    $this->actingAs($this->woven)->get(route('production.index'))->assertOk()
        ->assertSee('Impor batch')
        ->assertSee('Kalender shift fixture-3x8 (NON-PRODUCTION FIXTURE)')
        ->assertSee('Belum ada data')
        ->assertSee('Formula KPI produksi belum disetujui');

    config(['production.shift_calendars' => []]);
    $this->actingAs($this->woven)->get(route('production.index'))->assertSee('Belum ada kalender shift yang disetujui (DEC-015)');
});

test('a team imports, commits, and reconciles a batch on the page; other teams never see it', function (): void {
    $path = Harness::file(BatchKind::Production, Harness::wovenDay());

    $page = Livewire::actingAs($this->woven)->test('pages::production.index')
        ->assertSet('loadable', ['PROD-WOVEN|production' => 'PROD-WOVEN — Aktual produksi (output, downtime, waste)'])
        ->set('loadTarget', 'PROD-WOVEN|production')
        ->set('sourceSystem', 'mes-woven')
        ->set('coverageDate', '2026-10-13')
        ->set('extractedAt', '2026-10-14T09:30')
        ->set('file', UploadedFile::fake()->createWithContent('woven.csv', (string) file_get_contents($path)))
        ->call('stage')
        ->assertHasNoErrors();
    @unlink($path);

    $batch = ProductionBatch::query()->firstOrFail();
    $page->call('commit', $batch->public_id)->assertHasNoErrors();

    Livewire::actingAs($this->woven)->test('pages::production.index', ['selected' => $batch->public_id])
        ->assertSee('Rekonsiliasi Aktual produksi')
        ->assertSee('2100.750 pcs')
        ->assertSee('150.125 m')
        ->assertSee('Rincian cocok dengan total batch')
        ->assertSee('2 baris tanpa reason code', escape: false);

    Livewire::actingAs($this->flexo)->test('pages::production.index', ['selected' => $batch->public_id])
        ->assertDontSee($batch->public_id)
        ->assertDontSee('2100.750')
        ->call('startReject', $batch->public_id)
        ->assertNotFound();

    Livewire::actingAs($this->flexo)->test('pages::production.index')
        ->set('loadTarget', 'PROD-WOVEN|production')
        ->call('stage')
        ->assertForbidden();
});

test('the PPIC team moves a work order on the board page; production teams only read it', function (): void {
    Harness::load($this->ppic, BatchKind::WorkOrders, ['WO-100,SO-1,C-1,P-1,WEAVE,M-01,3000,pcs,2026-10-10,2026-10-15,2026-10-12,partial,approved,emergency'], 'COMSUP-ADMIN-PPIC', '2026-10-14');

    Livewire::actingAs($this->ppic)->test('pages::ppic.index')
        ->assertSee('WO-100')
        ->assertSee('Overdue')
        ->assertSee('Material belum siap')
        ->call('startMove', 'WO-100')
        ->set('target', PpicStatus::InProduction->value)
        ->call('move')
        ->assertHasErrors('action')
        ->set('reason', 'Material datang terlambat')
        ->call('move')
        ->assertHasNoErrors()
        ->assertSet('moving', '')
        ->call('showHistory', 'WO-100')
        ->assertSee('Material datang terlambat');

    expect(PpicStatusChange::query()->sole()->to_status)->toBe(PpicStatus::InProduction);

    Livewire::actingAs($this->woven)->test('pages::ppic.index')
        ->assertSee('WO-100')
        ->assertSee('In Production')
        ->assertDontSee('Ubah status')
        ->call('startMove', 'WO-100')
        ->assertForbidden();

    Livewire::actingAs($this->ppic)->test('pages::ppic.index')
        ->set('status', PpicStatus::Completed->value)
        ->assertDontSee('WO-100')
        ->set('status', PpicStatus::InProduction->value)
        ->assertSee('WO-100');
});
