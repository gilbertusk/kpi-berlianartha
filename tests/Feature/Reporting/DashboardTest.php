<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Notification\Application\NotificationMessage;
use App\Modules\Notification\Application\NotificationService;
use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\Workflow\Application\CorrectionService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\WorkflowScenario;

/*
 * Role dashboards on a period in review (TEST-ONLY scorecard policy):
 * what each role sees, what it must not see, and how many queries it costs.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    $this->scenario = new WorkflowScenario;

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
});

test('each role gets its own dashboard sections and nothing else', function (string $roleKey, array $sees, array $hidden): void {
    $response = $this->actingAs($this->users[$roleKey])->get(route('dashboard'))->assertOk();

    foreach ($sees as $text) {
        $response->assertSee($text);
    }

    foreach ($hidden as $text) {
        $response->assertDontSee($text);
    }
})->with([
    'management' => ['management', ['Scorecard dalam scope Anda', 'Koreksi menunggu keputusan', 'Kelengkapan aktual'], ['KPI saya', 'Kesehatan sistem', 'Audit dan rekonsiliasi', 'Halaman team', 'Kesiapan menuju']],
    'hr' => ['hr_performance', ['Scorecard dalam scope Anda', 'Kesiapan menuju Finalized', '4 scorecard belum mendapat approval final.', 'Scorecard menunggu pengajuan ulang'], ['KPI saya', 'Kesehatan sistem', 'Halaman team']],
    'department head' => ['department_head', ['Scorecard dalam scope Anda', 'Scorecard menunggu keputusan', 'Halaman team', 'Corrective action belum selesai'], ['KPI saya', 'Kesehatan sistem', 'Kesiapan menuju', 'Audit dan rekonsiliasi']],
    'team leader' => ['team_leader', ['Halaman team', 'Kelengkapan aktual', 'Corrective action belum selesai'], ['KPI saya', 'Kesehatan sistem', 'Kesiapan menuju']],
    'employee' => ['employee', ['KPI saya', 'SC-QUALITY', 'Terverifikasi', 'Lihat scorecard saya'], ['Scorecard dalam scope Anda', 'Kelengkapan aktual', 'Kesehatan sistem', 'W-002', 'Halaman team', 'Menunggu tindakan Anda']],
    'auditor' => ['auditor', ['Audit dan rekonsiliasi', 'Perhitungan tersimpan: 4', 'Scorecard dalam scope Anda'], ['KPI saya', 'Kesehatan sistem', 'Halaman team', 'Menunggu tindakan Anda']],
    'system admin' => ['system_admin', ['Kesehatan sistem dan konfigurasi', 'Belum ada (DEC-009)', 'Nonaktif (DEC-014 belum diputuskan)'], ['Scorecard dalam scope Anda', 'KPI saya', 'Band uji', 'Kelengkapan aktual']],
]);

test('figures are counts of stored records inside the scope of the account', function (): void {
    // HR sees all four scorecards; the PROD head only those of PROD; another department's head none.
    Livewire::actingAs($this->users['hr_performance'])->test('pages::dashboard')
        ->assertSee('Lengkap: 4')
        ->assertSee('Target wajib: 4')
        ->assertSee('Terverifikasi: 4');

    Livewire::actingAs($this->users['department_head'])->test('pages::dashboard')
        ->assertSee('PROD-WOVEN')
        ->assertDontSee('AR-001')
        ->assertDontSee('W-001');

    Livewire::actingAs($this->users['other_head'])->test('pages::dashboard')
        ->assertDontSee('PROD-WOVEN')
        ->assertDontSee('AR-001')
        ->assertSee('Lengkap: 0');

    // Management reads unit scorecards and aggregates, never an individual employee's line.
    Livewire::actingAs($this->users['management'])->test('pages::dashboard')
        ->assertSee('PROD-WOVEN')
        ->assertDontSee('W-001');
});

test('red KPIs appear only once a threshold is approved, and only inside the scope', function (): void {
    Livewire::actingAs($this->users['hr_performance'])->test('pages::dashboard')
        ->assertSee('KPI merah belum dapat ditampilkan')
        ->assertDontSee('KPI dengan skor di bawah');

    Config::set('dashboard.red_kpi_score_below', '100');

    Livewire::actingAs($this->users['hr_performance'])->test('pages::dashboard')
        ->assertSee('KPI dengan skor di bawah 100')
        ->assertSee('SC-QUALITY')
        ->assertSee('SC-DSO')
        ->assertDontSee('KPI merah belum dapat ditampilkan');

    // The team's own KPI scored 110, so the PROD head has nothing below 100.
    Livewire::actingAs($this->users['department_head'])->test('pages::dashboard')
        ->assertSee('Tidak ada KPI di bawah ambang')
        ->assertDontSee('SC-DSO');

    // A threshold that is not a plain decimal is ignored rather than used.
    Config::set('dashboard.red_kpi_score_below', '100 OR 1=1');
    Livewire::actingAs($this->users['hr_performance'])->test('pages::dashboard')->assertSee('KPI merah belum dapat ditampilkan');
});

test('task counters follow the workflow and a period outside the scope cannot be selected', function (): void {
    $hr = $this->scenario->hr();
    app(CorrectionService::class)->request(WorkflowScenario::actor($hr), $hr, $this->scenario->actual('SC-QUALITY', 'W-001')->id, '80', null, 'Salah input');

    Livewire::actingAs($this->users['management'])->test('pages::dashboard')
        ->assertSeeInOrder(['Koreksi menunggu keputusan', '1']);

    Livewire::actingAs($this->users['department_head'])->test('pages::dashboard')
        ->assertSeeInOrder(['Scorecard menunggu keputusan', '3']);

    // An unknown or foreign period code falls back to what the account may see.
    Livewire::withQueryParams(['periode' => 'P-TIDAK-ADA'])->actingAs($this->users['employee'])->test('pages::dashboard')
        ->assertOk()
        ->assertSee('KPI saya — P-2026-11');
});

test('the dashboard of each role stays within its query budget', function (string $roleKey, int $budget): void {
    $this->actingAs($this->users[$roleKey]);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get(route('dashboard'))->assertOk();

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual($budget, "{$roleKey} dashboard ran {$queries} queries");
})->with([
    ['management', 110],
    ['hr_performance', 110],
    ['department_head', 110],
    ['team_leader', 90],
    ['employee', 60],
    ['auditor', 90],
    ['system_admin', 40],
]);

test('dashboard queries do not grow with the number of notifications or scorecards read', function (): void {
    $user = $this->users['hr_performance'];
    $count = function () use ($user): int {
        $this->actingAs($user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('dashboard'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $before = $count();

    for ($index = 0; $index < 30; $index++) {
        app(NotificationService::class)->notify([$user->id], new NotificationMessage(NotificationCategory::Reminder, 'Judul', 'Isi', "bulk-{$index}"));
    }

    // Thirty more notifications cost no additional query (one query of slack for request bootstrapping).
    expect($count())->toBeLessThanOrEqual($before + 1);
});
