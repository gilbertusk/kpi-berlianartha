<?php

declare(strict_types=1);

use App\Modules\ActualEvidence\Application\ActualAccess;
use App\Modules\ActualEvidence\Application\ActualQuery;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\RoleCapabilityMatrix;
use App\Modules\Reporting\Application\ReportCatalog;
use App\Modules\Reporting\Application\Reports\AuditLogReport;
use App\Modules\Scoring\Application\ScoreAccess;
use App\Modules\Scoring\Application\ScorecardQuery;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculationLine;
use App\Modules\TeamReview\Application\ActionDraft;
use App\Modules\TeamReview\Application\IssueDraft;
use App\Modules\TeamReview\Application\TeamReviewService;
use App\Modules\Workflow\Application\CorrectionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\ReportHarness;
use Tests\Support\WorkflowScenario;

/*
 * Reports against their sources: every report must show what the scorecard
 * pages and stored snapshots show, and only inside the reader's scope.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    $this->scenario = new WorkflowScenario;
    $this->period = ['period' => $this->scenario->period->public_id];
    $this->auditor = AccessFixtures::userWithRole(Role::Auditor, 'BAL');
    $this->otherHead = AccessFixtures::userWithRole(Role::DepartmentHead, 'COMSUP');
});

test('the scorecard report equals the stored calculations and the scorecard page', function (): void {
    $hr = $this->scenario->hr();
    $rows = collect(ReportHarness::rows($hr, 'scorecards', $this->period));
    $summary = app(ScorecardQuery::class)->summary($this->scenario->period, ScoreAccess::everything());

    expect($rows)->toHaveCount($summary['subjects'])
        ->and($rows->where('Status perhitungan', 'Lengkap'))->toHaveCount($summary['complete']);

    foreach (['W-001', 'W-002', 'PROD-WOVEN', 'AR-001'] as $subject) {
        $row = $rows->firstWhere('Kode subjek', $subject);
        $calculation = ScorecardCalculation::query()->where('period_assignment_id', $this->scenario->scorecards->assignment($subject)->id)->orderByDesc('sequence_number')->firstOrFail();

        expect($row['Skor hasil perhitungan'])->toBe($calculation->final_score)
            ->and($row['Rating hasil perhitungan'])->toBe($calculation->rating_label)
            ->and($row['Skor berlaku'])->toBe($calculation->final_score)
            ->and($row['Input hash'])->toBe($calculation->input_hash)
            ->and($row['Status approval'])->toBe('Menunggu review');

        // The same figure is on the scorecard page.
        $this->actingAs($hr)->get(route('scorecards.show', [$this->scenario->period, $this->scenario->scorecards->assignment($subject)->public_id]))->assertSee($calculation->final_score);
    }

    // Calibration changes the score that counts, never the calculated one.
    $this->scenario->approve($this->scenario->manager, 'W-001');
    $this->scenario->calibrate($hr, 'W-001', '90', 'Forum kalibrasi');
    $calibrated = collect(ReportHarness::rows($hr, 'scorecards', [...$this->period, 'employee' => 'W-001']))->sole();

    expect($calibrated['Skor berlaku'])->toBe('90.0000')
        ->and($calibrated['Dikalibrasi'])->toBe('ya')
        ->and($calibrated['Skor hasil perhitungan'])->toBe($rows->firstWhere('Kode subjek', 'W-001')['Skor hasil perhitungan'])
        ->and($calibrated['Status approval'])->toBe('Menunggu approval final');
});

test('KPI lines, rating distribution, and calculations reconcile with the snapshot', function (): void {
    $hr = $this->scenario->hr();
    $lines = collect(ReportHarness::rows($hr, 'kpi_lines', $this->period));

    expect($lines)->toHaveCount(ScorecardCalculationLine::query()->count());

    foreach ($lines as $row) {
        $stored = ScorecardCalculationLine::query()
            ->where('kpi_code', $row['Kode KPI'])
            ->whereHas('calculation.assignment', fn ($query) => $query->where('subject_code', $row['Kode subjek']))
            ->firstOrFail();

        expect($row['Skor KPI'])->toBe($stored->kpi_score)
            ->and($row['Target'])->toBe($stored->target_value)
            ->and($row['Nilai terukur'])->toBe($stored->measured_value)
            ->and($row['Di bawah ambang'])->toBe('ambang belum disetujui');
    }

    // Red KPIs exist only once a threshold is approved.
    expect(AccessFixtures::violatedRule(fn () => ReportHarness::rows($hr, 'kpi_lines', [...$this->period, 'red_only' => true])))->toBe('threshold_not_approved');

    Config::set('dashboard.red_kpi_score_below', '100');
    $red = collect(ReportHarness::rows($hr, 'kpi_lines', [...$this->period, 'red_only' => true]));

    expect($red->pluck('Kode KPI')->sort()->values()->all())->toBe(['SC-DSO', 'SC-QUALITY', 'SC-QUALITY'])
        ->and($red->pluck('Di bawah ambang')->unique()->all())->toBe(['ya'])
        ->and(ReportHarness::rows($hr, 'kpi_lines', [...$this->period, 'kpi' => 'SC-DSO']))->toHaveCount(1);

    $ratings = collect(ReportHarness::rows($hr, 'rating_distribution', $this->period));
    expect($ratings->sum(fn (array $row): int => (int) $row['Jumlah scorecard']))->toBe(4);

    $calculations = collect(ReportHarness::rows($hr, 'calculations', $this->period));
    expect($calculations)->toHaveCount(ScorecardCalculation::query()->count())
        ->and($calculations->pluck('Aturan scorecard')->unique()->all())->toBe(['test-only@1']);
});

test('the completeness report equals the completeness summary of the actuals page', function (): void {
    $hr = $this->scenario->hr();
    $rows = collect(ReportHarness::rows($hr, 'completeness', $this->period));
    $summary = app(ActualQuery::class)->completeness($this->scenario->period, ActualAccess::everything());

    expect($rows)->toHaveCount($summary['targets'])
        ->and($rows->where('Status', 'Terverifikasi'))->toHaveCount($summary['verified'])
        ->and($rows->where('Status', 'Belum diisi'))->toHaveCount($summary['missing'])
        ->and(ReportHarness::rows($hr, 'completeness', [...$this->period, 'status' => 'missing']))->toHaveCount($summary['missing'])
        ->and(ReportHarness::rows($hr, 'completeness', [...$this->period, 'source' => 'manual']))->toHaveCount(4)
        ->and(ReportHarness::rows($hr, 'completeness', [...$this->period, 'source' => 'import']))->toHaveCount(0);
});

test('approval, correction, definition, and audit reports show the recorded history', function (): void {
    $hr = $this->scenario->hr();
    $this->scenario->approve($this->scenario->manager, 'W-001', 'Sesuai');
    app(CorrectionService::class)->request(WorkflowScenario::actor($hr), $hr, $this->scenario->actual('SC-QUALITY', 'W-002')->id, '85', null, 'Salah input', 'BA-9');

    $status = collect(ReportHarness::rows($hr, 'approvals', $this->period));
    expect($status)->toHaveCount(4)
        ->and($status->firstWhere('Kode subjek', 'W-001')['Tahap menunggu'])->toBe('Kalibrasi')
        ->and($status->firstWhere('Kode subjek', 'AR-001')['Kekosongan approver'])->toBe('Belum dikonfigurasi')
        ->and(ReportHarness::rows($hr, 'approvals', [...$this->period, 'status' => 'reviewed']))->toHaveCount(1);

    $history = collect(ReportHarness::rows($hr, 'approval_history', $this->period))->sole();
    expect($history['Keputusan'])->toBe('Disetujui')
        ->and($history['Role'])->toBe('department_head')
        ->and($history['Catatan'])->toBe('Sesuai');

    $correction = collect(ReportHarness::rows($hr, 'corrections', $this->period))->sole();
    expect($correction['Nilai diminta'])->toBe('85')
        ->and($correction['Referensi bukti'])->toBe('BA-9')
        ->and($correction['Status'])->toBe('Menunggu keputusan');

    $definitions = collect(ReportHarness::rows($hr, 'kpi_definitions', $this->period));
    expect($definitions->pluck('Kode KPI')->sort()->values()->all())->toBe(['SC-DSO', 'SC-QUALITY', 'SC-TEAM'])
        ->and($definitions->firstWhere('Kode KPI', 'SC-QUALITY')['Jumlah target pada periode'])->toBe('2');

    $audit = collect(ReportHarness::rows($this->auditor, 'audit_log', ['from' => AccessFixtures::TODAY, 'to' => AccessFixtures::TODAY]));
    expect($audit->pluck('Aksi'))->toContain('scorecard_review.reviewed', 'correction.requested')
        ->and(ReportHarness::rows($this->auditor, 'audit_log', ['from' => AccessFixtures::TODAY, 'to' => AccessFixtures::TODAY, 'entity' => 'correction_request']))->toHaveCount(1)
        ->and(AccessFixtures::violatedRule(fn () => ReportHarness::rows($this->auditor, 'audit_log', [])))->toBe('date_range_required')
        ->and(AccessFixtures::violatedRule(fn () => ReportHarness::rows($this->auditor, 'audit_log', ['from' => '2026-01-01', 'to' => '2026-10-12'])))->toBe('date_range_too_long')
        ->and(AuditLogReport::MAX_DAYS)->toBe(92)
        // Reading the audit trail is never delegable.
        ->and(Capability::AuditReportView->isDelegable())->toBeFalse()
        ->and((new RoleCapabilityMatrix)->delegableCapabilitiesFor(Role::HrPerformance))->not->toContain(Capability::AuditReportView);
});

test('the corrective action report counts overdue from the due date', function (): void {
    $leader = $this->scenario->scorecards->leader;
    $service = app(TeamReviewService::class);
    $issue = $service->recordIssue(WorkflowScenario::actor($leader), $leader, new IssueDraft(AccessFixtures::unit('PROD-WOVEN')->id, AccessFixtures::TODAY, 'Benang putus'));
    $service->assignAction(WorkflowScenario::actor($leader), $leader, $issue->id, new ActionDraft('Setel tension', $this->scenario->scorecards->staff->id, '2026-10-13', 'high'));
    $service->assignAction(WorkflowScenario::actor($leader), $leader, $issue->id, new ActionDraft('Ganti suku cadang', $this->scenario->scorecards->staff->id, '2026-10-30', 'low'));

    expect(ReportHarness::rows($leader, 'corrective_actions', ['status' => 'overdue']))->toHaveCount(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-16 01:00:00', 'UTC'));
    $overdue = collect(ReportHarness::rows($leader, 'corrective_actions', ['status' => 'overdue']))->sole();

    expect($overdue['Tindakan'])->toBe('Setel tension')
        ->and($overdue['Hari melewati tenggat'])->toBe('3')
        ->and(ReportHarness::rows($leader, 'corrective_actions'))->toHaveCount(2)
        ->and(ReportHarness::rows($leader, 'corrective_actions', ['from' => '2026-10-20', 'to' => '2026-10-31']))->toHaveCount(1)
        // Another department sees none of it.
        ->and(ReportHarness::rows($this->otherHead, 'corrective_actions'))->toHaveCount(0)
        ->and(fn () => ReportHarness::rows($this->otherHead, 'corrective_actions', ['unit' => 'PROD-WOVEN']))->toThrow(AuthorizationException::class);
});

test('each role gets only the reports and rows of its scope', function (): void {
    $catalog = app(ReportCatalog::class);
    $keys = fn ($user): array => array_map(fn ($report): string => $report->key(), $catalog->availableTo($user));

    expect($keys($this->scenario->scorecards->staff))->toBe([])
        ->and($keys(AccessFixtures::userWithRole(Role::SystemAdmin)))->toBe([])
        ->and($keys($this->scenario->hr()))->toContain('scorecards', 'audit_log', 'corrections', 'kpi_definitions')
        ->and($keys($this->auditor))->toContain('scorecards', 'audit_log')
        ->and($keys($this->scenario->management))->not->toContain('audit_log')
        ->and($keys($this->scenario->manager))->not->toContain('audit_log', 'corrections');

    // The assessed employee and a system admin cannot run any report.
    foreach ([$this->scenario->scorecards->staff, AccessFixtures::userWithRole(Role::SystemAdmin)] as $user) {
        expect(fn () => ReportHarness::rows($user, 'scorecards', $this->period))->toThrow(AuthorizationException::class);
    }

    // Managers see unit scorecards of their scope, never an individual employee's row.
    $head = collect(ReportHarness::rows($this->scenario->manager, 'scorecards', $this->period));
    expect($head->where('Jenis subjek', 'employee'))->toHaveCount(0)
        ->and($head->pluck('Kode subjek'))->toContain('PROD-WOVEN')
        ->and($head->pluck('Unit')->filter(fn (string $unit): bool => ! str_starts_with($unit, 'PROD'))->all())->toBe([])
        ->and(collect(ReportHarness::rows($this->scenario->manager, 'kpi_lines', $this->period))->pluck('Kode subjek')->unique()->all())->toBe(['PROD-WOVEN'])
        ->and(ReportHarness::rows($this->scenario->manager, 'scorecards', [...$this->period, 'employee' => 'W-001']))->toHaveCount(0);

    // Unit filter: inside the scope it narrows, outside it is refused like an unknown unit.
    expect(ReportHarness::rows($this->scenario->hr(), 'scorecards', [...$this->period, 'unit' => 'ACC', 'status' => 'complete']))->toHaveCount(1)
        ->and(ReportHarness::rows($this->scenario->hr(), 'scorecards', [...$this->period, 'level' => 'team', 'status' => 'complete']))->toHaveCount(1)
        ->and(fn () => ReportHarness::rows($this->scenario->manager, 'scorecards', [...$this->period, 'unit' => 'ACC-AR']))->toThrow(AuthorizationException::class)
        ->and(fn () => ReportHarness::rows($this->scenario->manager, 'scorecards', [...$this->period, 'unit' => 'TIDAK-ADA']))->toThrow(AuthorizationException::class)
        ->and(fn () => ReportHarness::rows($this->otherHead, 'completeness', [...$this->period, 'unit' => 'PROD-WOVEN']))->toThrow(AuthorizationException::class)
        ->and(fn () => ReportHarness::rows($this->scenario->management, 'audit_log', ['from' => AccessFixtures::TODAY, 'to' => AccessFixtures::TODAY]))->toThrow(AuthorizationException::class)
        ->and(fn () => ReportHarness::rows($this->scenario->hr(), 'tidak_ada', $this->period))->toThrow(AuthorizationException::class);
});

test('filters are validated by shape and refused when a report does not offer them', function (array $filters, string $report): void {
    $filters = array_key_exists('period', $filters) ? $filters : [...$this->period, ...$filters];

    expect(AccessFixtures::violatedRule(fn () => ReportHarness::rows($this->scenario->hr(), $report, $filters)))->toBe('invalid_filter');
})->with([
    'path traversal in unit' => [['unit' => '../../etc/passwd'], 'scorecards'],
    'sql in kpi' => [['kpi' => "X' OR 1=1 --"], 'kpi_lines'],
    'unknown filter' => [['admin' => '1'], 'scorecards'],
    'bad level' => [['level' => 'galaxy'], 'scorecards'],
    'bad period id' => [['period' => 'not-a-ulid'], 'scorecards'],
    'bad date' => [['from' => '2026-02-31', 'to' => '2026-03-01', 'period' => null], 'corrective_actions'],
    'reversed dates' => [['from' => '2026-03-02', 'to' => '2026-03-01', 'period' => null], 'corrective_actions'],
    'filter not offered' => [['kpi' => 'SC-DSO'], 'scorecards'],
    'status the report does not have' => [['status' => 'bogus'], 'scorecards'],
    'status of another report' => [['status' => 'overdue'], 'calculations'],
    'array instead of text' => [['unit' => ['PROD']], 'scorecards'],
]);

test('a report runs in a bounded number of queries', function (string $report, int $budget): void {
    $hr = $this->scenario->hr();
    DB::flushQueryLog();
    DB::enableQueryLog();

    ReportHarness::rows($hr, $report, $this->period);

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual($budget, "{$report} ran {$queries} queries");
})->with([
    ['scorecards', 25],
    ['kpi_lines', 25],
    ['rating_distribution', 20],
    ['completeness', 20],
    ['calculations', 25],
]);
