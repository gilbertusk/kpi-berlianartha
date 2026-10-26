<?php

declare(strict_types=1);

use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\Reporting\Domain\ExportStatus;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportExport;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Workflow\Domain\CorrectionStatus;
use App\Modules\Workflow\Domain\ReviewState;
use App\Modules\Workflow\Infrastructure\Persistence\Models\CorrectionRequest;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AccessFixtures;
use Tests\Support\BrowserJourney;

/*
 * Every wait after an action is on the message only that action shows: a
 * status label already on the page (a filter option, the navigation) would
 * let the test run ahead of the Livewire request.
 *
 * Step 19 — the core journey through the real UI in a real browser
 * (Playwright via pest-plugin-browser). Every decision of the journey is a
 * click by the account that holds it; setup that is not part of the journey
 * (fixtures, the other scorecards of the period) goes through the same
 * services the UI calls. The scorecard policy is the TEST-ONLY fixture:
 * DEC-004 is open, so no official policy exists (see SCORING.md).
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    Storage::fake('s3');
    $this->journey = new BrowserJourney;
});

test('HR, employee, team leader, manager, HR, and Management take one scorecard from actual to lock, correction, export, and audit', function (): void {
    $journey = $this->journey;
    $scenario = $journey->scorecards;
    $period = $scenario->period;
    $quality = $scenario->target('SC-QUALITY', 'W-001');

    // Employee: enters the own actual, attaches evidence, and submits.
    $this->actingAs($scenario->staff);
    $page = BrowserJourney::open(route('actuals.entry', $period))
        ->assertSee('SC-QUALITY')
        ->assertDontSee('AR-001')
        ->fill("#value-{$quality->public_id}", '95.5')
        ->press('Simpan draft halaman ini')
        ->waitForText('Draft aktual disimpan: 1 baru');

    // The Livewire upload itself (multipart `files[]`) cannot pass the
    // in-process test server of pest-plugin-browser, which keeps nested
    // field names flat; it is covered by ActualPagesTest. The evidence is
    // attached through the service the upload calls, then checked in the UI.
    $journey->attachEvidence($quality);
    $page = BrowserJourney::open(route('actuals.entry', $period))
        ->click('Bukti & riwayat SC-QUALITY W-001')
        ->waitForText('laporan-mutu.pdf');
    BrowserJourney::acceptConfirmations($page);
    $page->press('Ajukan draft')->waitForText('1 aktual diajukan untuk verifikasi.')->assertNoJavaScriptErrors();

    $journey->submitOtherActuals();

    // Team leader: verifies what is waiting in the own team.
    $this->actingAs($scenario->leader);
    $page = BrowserJourney::open(route('actuals.entry', $period))->assertSee('W-001');
    BrowserJourney::acceptConfirmations($page);
    $page->press('Verifikasi aktual yang diajukan')->waitForText('3 aktual terverifikasi.');

    expect(KpiActual::query()->where('period_target_id', $quality->id)->firstOrFail()->versions()->orderByDesc('version_number')->value('status'))->toBe(ActualStatus::Verified);

    $journey->verifyAccounting();

    // HR: calculates and moves the period into review.
    $this->actingAs($scenario->hr());
    BrowserJourney::open(route('scorecards.period', $period))
        ->fill('reason', 'Perhitungan E2E')
        // The panel heading has the same text as the button.
        ->click('form[wire\:submit="calculate"] button')
        ->waitForText('Perhitungan selesai: 4 baru');

    $page = BrowserJourney::open(route('approvals.period', $period))->assertSee('Semua pemeriksaan lulus');
    BrowserJourney::acceptConfirmations($page);
    $page->press('Lanjutkan ke Review')->waitForText('Periode berpindah ke status Review.');
    expect($period->refresh()->status)->toBe(PeriodStatus::Review);

    // Manager review, HR calibration, Management final approval of W-001.
    $reviewUrl = route('approvals.review', $journey->review('W-001'));

    $this->actingAs($journey->manager);
    BrowserJourney::open($reviewUrl)->assertSee('Keputusan Anda: Review')->press('Setujui review')->waitForText('Scorecard disetujui.');

    $this->actingAs($scenario->hr());
    BrowserJourney::open($reviewUrl)->press('Simpan kalibrasi')->waitForText('Kalibrasi disimpan.');

    $this->actingAs($journey->management);
    BrowserJourney::open($reviewUrl)->press('Beri approval final')->waitForText('Scorecard disetujui.');
    expect($journey->review('W-001')->state)->toBe(ReviewState::FinalApproved);

    $journey->approveOtherScorecards();

    // HR: finalizes and locks after the checklist passes.
    $this->actingAs($scenario->hr());
    $page = BrowserJourney::open(route('approvals.period', $period));
    BrowserJourney::acceptConfirmations($page);
    $page->press('Lanjutkan ke Finalized')->waitForText('Periode berpindah ke status Finalized.');
    BrowserJourney::acceptConfirmations($page);
    $page->press('Kunci periode')->waitForText('Periode berpindah ke status Locked.');
    expect($period->refresh()->status)->toBe(PeriodStatus::Locked);

    // A locked actual cannot be edited in place.
    $this->actingAs($scenario->staff);
    BrowserJourney::open(route('actuals.entry', $period))->assertMissing("#value-{$quality->public_id}");

    // HR requests a correction; Management (another person) approves it.
    $calculationBefore = $journey->review('W-001')->scorecard_calculation_id;
    $this->actingAs($scenario->hr());
    BrowserJourney::open($reviewUrl)
        ->select('correctionActual', KpiActual::query()->where('period_target_id', $quality->id)->value('public_id'))
        ->fill('correctionValue', '80')
        ->fill('correctionReason', 'Salah input laporan mutu (E2E)')
        ->press('Kirim permintaan koreksi')
        ->waitForText('Permintaan koreksi dikirim dan menunggu persetujuan.');

    $this->actingAs($journey->management);
    $page = BrowserJourney::open(route('approvals.corrections'))->click('Putuskan SC-QUALITY W-001')->waitForText('Setujui koreksi');
    BrowserJourney::acceptConfirmations($page);
    $page->press('Setujui koreksi')->waitForText('Koreksi disetujui: versi aktual baru dibuat');

    $request = CorrectionRequest::query()->latest('id')->firstOrFail();
    $calculationAfter = ScorecardCalculation::query()->findOrFail($journey->review('W-001')->scorecard_calculation_id);
    expect($request->status)->toBe(CorrectionStatus::Approved)
        ->and($period->refresh()->status)->toBe(PeriodStatus::Locked)
        ->and($calculationAfter->id)->not->toBe($calculationBefore)
        ->and($calculationAfter->previous_calculation_id)->toBe($calculationBefore);

    // HR exports the scorecard report; the file states its metadata.
    $this->actingAs($scenario->hr());
    BrowserJourney::open(route('reports.index'))
        ->select('report', 'scorecards')
        ->select('period', (string) $period->public_id)
        ->press('Tampilkan')
        ->waitForText('W-001')
        ->click("button[wire\\:click=\"export('xlsx')\"]")
        ->waitForText('Ekspor masuk antrean.')
        ->assertSee('Unduh');
    expect(ReportExport::query()->where('report_key', 'scorecards')->value('status'))->toBe(ExportStatus::Completed);

    // Auditor: traces the correction and the approvals without any edit control.
    $this->actingAs(AccessFixtures::userWithRole(Role::Auditor, 'BAL'));
    BrowserJourney::open(route('reports.index'))
        ->select('report', 'audit_log')
        ->fill('entity', 'correction_request')
        ->press('Tampilkan')
        ->waitForText('correction_request')
        ->assertDontSee('Kirim permintaan koreksi');
    BrowserJourney::open($reviewUrl)
        ->assertSee('Riwayat approval')
        ->assertDontSee('Setujui review')
        ->assertDontSee('Kirim permintaan koreksi')
        ->assertNoJavaScriptErrors();
});

test('a period is created, opened, and moved to actual entry by HR in the browser', function (): void {
    $scenario = $this->journey->scorecards;
    $hr = $scenario->hr();
    $draft = $scenario->fixtures->draft(['code' => 'P-2026-12', 'name' => 'Desember 2026', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);

    $this->actingAs($hr);
    $page = BrowserJourney::open(route('periods.show', $draft))->assertSee('Desember 2026');
    BrowserJourney::acceptConfirmations($page);
    $page->press('Buka periode')->waitForText('Periode dibuka. Snapshot penugasan dan daftar target sudah dibuat.');

    expect($draft->refresh()->status)->toBe(PeriodStatus::Open);
});
