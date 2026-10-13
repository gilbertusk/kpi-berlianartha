<?php

declare(strict_types=1);

use App\Modules\ActualEvidence\Application\EvidenceService;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\ScorecardScenario;

/*
 * Direct URL, forged Livewire action, and export tests for scorecards.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    Storage::fake('s3');
    $this->scenario = new ScorecardScenario;
    $this->period = $this->scenario->period;

    // An employee who joined after the period opened: not part of its snapshot.
    $outsider = AccessFixtures::user('Pegawai baru');
    AccessFixtures::employee($outsider, 'ACC-AR', number: 'AR-NEW');
    AccessFixtures::grant($outsider, Role::Employee);

    $this->users = [
        'management' => AccessFixtures::userWithRole(Role::Management, 'BAL'),
        'department_head' => AccessFixtures::userWithRole(Role::DepartmentHead, 'PROD'),
        'team_leader' => $this->scenario->leader,
        'employee' => $this->scenario->staff,
        'hr_performance' => $this->scenario->hr(),
        'auditor' => AccessFixtures::userWithRole(Role::Auditor, 'BAL'),
        'system_admin' => AccessFixtures::userWithRole(Role::SystemAdmin),
        'outsider' => $outsider,
    ];

    $this->show = fn (string $subject): string => route('scorecards.show', [$this->period, $this->scenario->assignment($subject)->public_id]);
});

function calculatedScenario(ScorecardScenario $scenario): void
{
    ScorecardScenario::enablePolicy();
    $scenario->standardActuals();
    $scenario->calculate();
}

test('scorecard pages open for exactly the accounts with a scorecard to see', function (string $roleKey, int $index, int $period): void {
    $this->actingAs($this->users[$roleKey])->get(route('scorecards.index'))->assertStatus($index);
    $this->actingAs($this->users[$roleKey])->get(route('scorecards.period', $this->period))->assertStatus($period);
})->with([
    ['management', 200, 200],
    ['department_head', 200, 200],
    ['team_leader', 200, 200],
    ['employee', 200, 200],
    ['hr_performance', 200, 200],
    ['auditor', 200, 200],
    ['system_admin', 403, 403],
    ['outsider', 200, 403],
]);

test('individual scorecards are visible to the employee, HR, and auditors only', function (string $roleKey, int $own, int $colleague, int $team): void {
    calculatedScenario($this->scenario);

    $this->actingAs($this->users[$roleKey])->get(($this->show)('W-001'))->assertStatus($own);
    $this->actingAs($this->users[$roleKey])->get(($this->show)('W-002'))->assertStatus($colleague);
    $this->actingAs($this->users[$roleKey])->get(($this->show)('PROD-WOVEN'))->assertStatus($team);
})->with([
    // ROLE_APPROVAL_MATRIX "Melihat score pribadi": C for Management, Manager, and Team Leader (not activated).
    'management' => ['management', 403, 403, 200],
    'department head' => ['department_head', 403, 403, 200],
    'team leader' => ['team_leader', 403, 403, 200],
    'employee W-001' => ['employee', 200, 403, 403],
    'hr' => ['hr_performance', 200, 200, 200],
    'auditor' => ['auditor', 200, 200, 200],
]);

test('the reconciliation list shows only scorecards in scope', function (): void {
    calculatedScenario($this->scenario);

    Livewire::actingAs($this->scenario->staff)->test('pages::scorecards.period', ['period' => $this->period])
        ->assertSee('W-001')
        ->assertDontSee('W-002')
        ->assertDontSee('AR-001')
        ->assertDontSee('Hitung scorecard');

    Livewire::actingAs($this->scenario->leader)->test('pages::scorecards.period', ['period' => $this->period])
        ->assertSee('PROD-WOVEN')
        ->assertDontSee('W-001')
        ->assertDontSee('AR-001');

    // A company-wide period has more subjects than one page; filters narrow it.
    Livewire::actingAs($this->scenario->hr())->test('pages::scorecards.period', ['period' => $this->period])
        ->assertSee('test-only@1')
        ->set('search', 'W-00')
        ->assertSee(['W-001', 'W-002', '101.3000', 'Band uji 4'])
        ->set('search', '')
        ->set('state', 'complete')
        ->assertSee(['W-001', 'AR-001', 'PROD-WOVEN'])
        ->assertDontSee('W-002')
        ->set('state', 'incomplete')
        ->assertSee('W-002')
        ->assertDontSee('AR-001');
});

test('HR calculates from the page with a reason; nobody else can, even with a forged action', function (): void {
    ScorecardScenario::enablePolicy();
    $this->scenario->standardActuals();

    Livewire::actingAs($this->scenario->hr())->test('pages::scorecards.period', ['period' => $this->period])
        ->assertSee('Hitung scorecard')
        ->call('calculate')
        ->assertHasErrors('action')
        ->set('reason', 'Perhitungan dari halaman')
        ->call('calculate')
        ->assertHasNoErrors()
        ->assertSee('4 baru, 0 tidak berubah');

    expect(ScorecardCalculation::query()->value('reason'))->toBe('Perhitungan dari halaman')
        ->and(AuditLog::query()->where('action', 'scorecard.calculation_run')->value('actor_role'))->toBe('hr_performance');

    foreach (['team_leader', 'management', 'auditor', 'employee'] as $roleKey) {
        Livewire::actingAs($this->users[$roleKey])->test('pages::scorecards.period', ['period' => $this->period])
            ->set('reason', 'Paksa')
            ->call('calculate')
            ->assertForbidden();
    }

    expect(ScorecardCalculation::query()->count())->toBe(4);
});

test('without an approved policy the page explains the block and calculating is refused', function (): void {
    Livewire::actingAs($this->scenario->hr())->test('pages::scorecards.period', ['period' => $this->period])
        ->assertSee('Perhitungan skor resmi diblokir')
        ->assertSee('DEC-004')
        ->assertDontSee('Hitung scorecard')
        ->set('reason', 'Paksa')
        ->call('calculate')
        ->assertHasErrors('action');

    $this->actingAs($this->scenario->hr())->get(route('scorecards.index'))->assertOk()->assertSee('DEC-004 belum disetujui');

    expect(ScorecardCalculation::query()->count())->toBe(0);
});

test('the scorecard page shows each KPI line, its source entries, and older calculations', function (): void {
    calculatedScenario($this->scenario);

    Livewire::actingAs($this->scenario->staff)->test('pages::scorecards.show', ['period' => $this->period, 'assignment' => $this->scenario->assignment('W-001')->public_id])
        ->assertSee(['SC-QUALITY', 'SC-TEAM', '57.3000', '44.0000', '101.3000', 'Band uji 4', 'test-only@1'])
        ->call('showLine', 1)
        ->assertSee('Entri aktual yang dipertimbangkan')
        ->assertSee('Dipakai')
        ->call('showLine', 99)
        ->assertNotFound();

    // A newer verified actual makes the latest calculation stale until it is recalculated.
    $this->scenario->enter($this->scenario->target('SC-QUALITY', 'W-002'), '90');
    $this->scenario->submitAndVerify();

    Livewire::actingAs($this->scenario->hr())->test('pages::scorecards.show', ['period' => $this->period, 'assignment' => $this->scenario->assignment('W-002')->public_id])
        ->assertSee('Data atau aturan berubah sejak perhitungan ini');

    $this->scenario->calculate('Ulang W-002');

    Livewire::actingAs($this->scenario->hr())->test('pages::scorecards.show', ['period' => $this->period, 'assignment' => $this->scenario->assignment('W-002')->public_id])
        ->assertDontSee('Data atau aturan berubah sejak perhitungan ini')
        ->assertSee('98.0000')
        ->set('sequence', 1)
        ->assertSee('Anda melihat perhitungan lama (#1)')
        ->assertSee('Missing — ditahan sampai data tersedia');
});

test('evidence opens from the scorecard only for an entry of the line and an account that may see the actual', function (): void {
    ScorecardScenario::enablePolicy();
    $quality = $this->scenario->target('SC-QUALITY', 'W-001');
    $dso = $this->scenario->target('SC-DSO', 'AR-001');
    $this->scenario->enter($quality, '95.5');
    $this->scenario->enter($this->scenario->target('SC-TEAM', 'PROD-WOVEN'), '110');
    $this->scenario->enter($dso, '80');

    $pdf = '%PDF-1.4
1 0 obj
<< /Type /Catalog >>
endobj
trailer
<< /Root 1 0 R >>
%%EOF
';
    $files = app(EvidenceService::class);
    $evidence = $files->attach($this->scenario->hrActor(), KpiActual::query()->where('period_target_id', $quality->id)->value('id'), 'laporan-mutu.pdf', $pdf);
    $foreign = $files->attach($this->scenario->hrActor(), KpiActual::query()->where('period_target_id', $dso->id)->value('id'), 'laporan-ar.pdf', $pdf);

    $this->scenario->submitAndVerify();
    $this->scenario->calculate();

    $page = fn ($user) => Livewire::actingAs($user)
        ->test('pages::scorecards.show', ['period' => $this->period, 'assignment' => $this->scenario->assignment('W-001')->public_id])
        ->call('showLine', 1);

    $page($this->scenario->staff)->assertSee('laporan-mutu.pdf')->call('openEvidence', $evidence->public_id)->assertRedirectContains('/evidence/');
    $page($this->scenario->hr())->call('openEvidence', $foreign->public_id)->assertNotFound();
    // The team KPI line of an employee's own scorecard: the team's actuals are not the employee's to open.
    Livewire::actingAs($this->scenario->staff)
        ->test('pages::scorecards.show', ['period' => $this->period, 'assignment' => $this->scenario->assignment('W-001')->public_id])
        ->call('showLine', 2)
        ->assertSee('Tidak tersedia untuk akun Anda');
});

test('the reconciliation export follows the viewer scope and is audited', function (string $roleKey, int $status, array $see, array $dontSee): void {
    calculatedScenario($this->scenario);

    $response = $this->actingAs($this->users[$roleKey])->get(route('scorecards.export', $this->period))->assertStatus($status);

    if ($status !== 200) {
        return;
    }

    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBFperiod_code,subject_type")
        ->and(AuditLog::query()->where('action', 'scorecard.exported')->value('actor_role'))->toBe($roleKey);

    foreach ($see as $text) {
        expect($csv)->toContain($text);
    }

    foreach ($dontSee as $text) {
        expect($csv)->not->toContain($text);
    }
})->with([
    'hr sees every subject' => ['hr_performance', 200, ['W-001', 'W-002', 'AR-001', '57.3000', '101.3000', 'test-only@1'], []],
    'auditor sees every subject' => ['auditor', 200, ['W-001', 'AR-001'], []],
    'team leader sees the team scorecard only' => ['team_leader', 200, ['PROD-WOVEN', '110.0000'], ['W-001', 'AR-001']],
    'an employee does not export' => ['employee', 403, [], []],
    'system admin cannot' => ['system_admin', 403, [], []],
]);
