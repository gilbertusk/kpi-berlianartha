<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Application\RoleAssignmentService;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Reporting\Application\ExportMetadata;
use App\Modules\Reporting\Application\ExportService;
use App\Modules\Reporting\Application\TooManyRows;
use App\Modules\Reporting\Domain\CellSanitizer;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Reporting\Domain\ExportStatus;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateReportExport;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportExport;
use App\Modules\Reporting\Infrastructure\Writers\XlsxExportWriter;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Workflow\Application\CorrectionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\ReportHarness;
use Tests\Support\WorkflowScenario;

/*
 * Exports: content equals the report, metadata is complete, the file cannot
 * be used to get around access, and the queue path is idempotent.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    Storage::fake('s3');
    $this->scenario = new WorkflowScenario;
    $this->filters = ['period' => $this->scenario->period->public_id];
    $this->exports = app(ExportService::class);
});

function requestExport(object $test, $user, string $report = 'scorecards', string $format = 'xlsx', ?array $filters = null): ReportExport
{
    return $test->exports->request(WorkflowScenario::actor($user), $user, $report, $format, $filters ?? $test->filters)->refresh();
}

test('an Excel export carries the same rows as the report and states its metadata', function (): void {
    $hr = $this->scenario->hr();
    $export = requestExport($this, $hr);

    expect($export->status)->toBe(ExportStatus::Completed)
        ->and($export->rows_written)->toBeGreaterThan(4)
        ->and($export->path)->toMatch('#^report-exports/\d{4}/\d{2}/[0-9A-Z]{26}\.xlsx$#')
        ->and($export->checksum_sha256)->toBe(hash('sha256', Storage::disk('s3')->get($export->path)))
        ->and($export->expires_at?->equalTo(CarbonImmutable::now()->addHours(72)))->toBeTrue()
        ->and($export->classification)->toBe(Classification::Restricted);

    $workbook = ReportHarness::workbook($export);
    $expected = ReportHarness::rows($hr, 'scorecards', $this->filters);

    expect($workbook['rows'])->toHaveCount(count($expected))
        ->and($workbook['header'])->toBe(array_keys($expected[0]));

    foreach ($expected as $index => $row) {
        expect($workbook['rows'][$index])->toBe(array_map(static fn (?string $cell): string => (string) $cell, array_values($row)));
    }

    expect($workbook['info']['Laporan'])->toBe('Scorecard per subjek')
        ->and($workbook['info']['Periode'])->toContain('P-2026-11')
        ->and($workbook['info']['Filter'])->toContain('period = ')
        ->and($workbook['info']['Scope organisasi'])->toBe('Seluruh perusahaan')
        ->and($workbook['info']['Dibuat pada'])->toContain('2026-10-12 08:00:00 (Asia/Jakarta)')
        ->and($workbook['info']['Dibuat oleh'])->toBe($hr->name)
        ->and($workbook['info']['Klasifikasi'])->toContain('Terbatas')
        ->and($workbook['info']['ID ekspor'])->toBe($export->public_id)
        ->and($workbook['info'])->toHaveKey('Template dan versi');

    expect(AuditLog::query()->where('entity_id', $export->public_id)->orderBy('id')->pluck('action')->all())->toBe(['report_export.requested', 'report_export.completed']);
});

test('the export of a scoped account contains only its scope', function (): void {
    $export = requestExport($this, $this->scenario->manager, 'kpi_lines');
    $workbook = ReportHarness::workbook($export);
    $subjects = array_unique(array_column($workbook['rows'], 2));

    expect($subjects)->toBe(['PROD-WOVEN'])
        ->and($workbook['info']['Scope organisasi'])->toContain('PROD')
        ->and($workbook['info']['Scope organisasi'])->not->toContain('ACC');
});

test('cells that a spreadsheet would run as formulas are neutralized', function (): void {
    expect(CellSanitizer::clean('=HYPERLINK("http://x","y")'))->toBe('\'=HYPERLINK("http://x","y")')
        ->and(CellSanitizer::clean('+1+1'))->toBe("'+1+1")
        ->and(CellSanitizer::clean('-1+cmd'))->toBe("'-1+cmd")
        ->and(CellSanitizer::clean('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(CellSanitizer::clean('  =1+1'))->toBe("'  =1+1")
        ->and(CellSanitizer::clean("\t=1+1"))->toBe("'\t=1+1")
        ->and(CellSanitizer::clean("\r=1+1"))->toBe("'\r=1+1")
        ->and(CellSanitizer::clean('-12.5000'))->toBe('-12.5000')
        ->and(CellSanitizer::clean('95.5'))->toBe('95.5')
        ->and(CellSanitizer::clean('Benang putus'))->toBe('Benang putus')
        ->and(CellSanitizer::clean("a\x00b\x07c"))->toBe('abc')
        ->and(CellSanitizer::clean(null))->toBe('');

    // End to end: a hostile reason typed into a correction reaches the file as text.
    $hr = $this->scenario->hr();
    app(CorrectionService::class)->request(WorkflowScenario::actor($hr), $hr, $this->scenario->actual('SC-QUALITY', 'W-002')->id, '85', null, "=cmd|' /C calc'!A0", '@SUM(1+1)');

    $workbook = ReportHarness::workbook(requestExport($this, $hr, 'corrections'));
    $row = $workbook['rows'][0];

    expect($row[8])->toBe("'=cmd|' /C calc'!A0")
        ->and($row[9])->toBe("'@SUM(1+1)");
});

test('a PDF export is produced for bounded reports and refused with a safe reason when too large', function (): void {
    $hr = $this->scenario->hr();
    $export = requestExport($this, $hr, 'rating_distribution', 'pdf');

    expect($export->status)->toBe(ExportStatus::Completed)
        ->and(substr((string) Storage::disk('s3')->get($export->path), 0, 5))->toBe('%PDF-')
        ->and($export->file_name)->toEndWith('.pdf');

    expect(config('reports.pdf_max_rows'))->toBe(500);

    Config::set('reports.pdf_max_rows', 2);
    $this->exports = app(ExportService::class);
    $tooLarge = requestExport($this, $hr, 'kpi_lines', 'pdf');

    expect($tooLarge->status)->toBe(ExportStatus::Failed)
        ->and($tooLarge->failure_code)->toBe('too_many_rows_for_pdf')
        ->and($tooLarge->failureMessage())->toContain('gunakan Excel')
        ->and($tooLarge->path)->toBeNull()
        ->and(AccessFixtures::violatedRule(fn () => requestExport($this, $hr, 'scorecards', 'csv')))->toBe('invalid_format');
});

test('an export cannot be requested, linked, or downloaded outside the owner and its access', function (): void {
    $hr = $this->scenario->hr();
    $staff = $this->scenario->scorecards->staff;
    $manager = $this->scenario->manager;

    expect(fn () => requestExport($this, $staff))->toThrow(AuthorizationException::class)
        ->and(fn () => requestExport($this, $manager, 'audit_log', 'xlsx', ['from' => AccessFixtures::TODAY, 'to' => AccessFixtures::TODAY]))->toThrow(AuthorizationException::class)
        ->and(fn () => requestExport($this, $manager, 'scorecards', 'xlsx', [...$this->filters, 'unit' => 'ACC-AR']))->toThrow(AuthorizationException::class)
        ->and(ReportExport::query()->count())->toBe(0);

    $export = requestExport($this, $hr);
    $link = $this->exports->downloadLink($hr, $export);

    // Another account cannot get a link, and cannot use the owner's link.
    expect(fn () => $this->exports->downloadLink($manager, $export))->toThrow(AuthorizationException::class);
    $this->actingAs($manager)->get($link)->assertForbidden();

    // No signature, a tampered viewer, or a guest: refused.
    $this->actingAs($hr)->get(route('reports.exports.download', ['export' => $export->public_id, 'viewer' => $hr->public_id]))->assertForbidden();
    $this->actingAs($manager)->get(str_replace($hr->public_id, $manager->public_id, $link))->assertForbidden();

    $response = $this->actingAs($hr)->get($link)->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(hash('sha256', $response->streamedContent()))->toBe($export->checksum_sha256)
        ->and($export->refresh()->download_count)->toBe(1)
        ->and(AuditLog::query()->where('action', 'report_export.downloaded')->where('entity_id', $export->public_id)->count())->toBe(1);

    auth()->logout();
    $this->get($link)->assertRedirect();
});

test('a download link expires, and so does the export itself', function (): void {
    $hr = $this->scenario->hr();
    $export = requestExport($this, $hr);
    $link = $this->exports->downloadLink($hr, $export);

    // The signed link is short-lived.
    $this->travelTo(CarbonImmutable::now()->addSeconds(301));
    $this->actingAs($hr)->get($link)->assertForbidden();

    // A fresh link works while the export is retained.
    $fresh = $this->exports->downloadLink($hr, $export);
    $this->actingAs($hr)->get($fresh)->assertOk();

    // After retention: no new link, and a link issued just before is gone too.
    $this->travelTo($export->expires_at->subSeconds(10));
    $late = $this->exports->downloadLink($hr, $export);
    $this->travelTo($export->expires_at->addSecond());

    expect(AccessFixtures::violatedRule(fn () => $this->exports->downloadLink($hr, $export)))->toBe('export_expired');
    $this->actingAs($hr)->get($late)->assertStatus(410);

    // The purge deletes the file and marks the export expired.
    $path = $export->path;
    $this->artisan('reports:purge-expired-exports')->expectsOutputToContain('purged: 1')->assertSuccessful();

    expect(Storage::disk('s3')->exists($path))->toBeFalse()
        ->and($export->refresh()->status)->toBe(ExportStatus::Expired)
        ->and($export->purged_at)->not->toBeNull();

    $this->artisan('reports:purge-expired-exports')->expectsOutputToContain('purged: 0')->assertSuccessful();
});

test('access is evaluated again in the worker and at download', function (): void {
    $manager = $this->scenario->manager;
    $assignment = RoleAssignment::query()->where('user_id', $manager->id)->firstOrFail();
    $revoke = function () use ($assignment): void {
        app(RoleAssignmentService::class)->revoke(Actor::system('test'), $assignment->id, $assignment->refresh()->lock_version, 'Mutasi');
        WorkflowScenario::flushAccess();
    };

    // Completed while authorized, role revoked before download.
    $done = requestExport($this, $manager);
    $link = $this->exports->downloadLink($manager, $done);

    // Requested while authorized, role revoked before the worker runs.
    Queue::fake();
    $queued = requestExport($this, $manager, 'kpi_lines');
    expect($queued->status)->toBe(ExportStatus::Queued);

    $revoke();

    $this->actingAs($manager)->get($link)->assertForbidden();
    expect($done->refresh()->download_count)->toBe(0);

    $this->exports->generate($queued->id);

    expect($queued->refresh()->status)->toBe(ExportStatus::Failed)
        ->and($queued->failure_code)->toBe('not_authorized')
        ->and($queued->path)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toHaveCount(1);
});

test('requesting and retrying are idempotent', function (): void {
    Queue::fake();
    $hr = $this->scenario->hr();

    $first = requestExport($this, $hr);
    $second = requestExport($this, $hr);

    expect($second->id)->toBe($first->id)
        ->and(ReportExport::query()->count())->toBe(1);
    Queue::assertPushed(GenerateReportExport::class, 1);

    // The worker picks a queued export up once.
    $this->exports->generate($first->id);
    $this->exports->generate($first->id);

    expect($first->refresh()->status)->toBe(ExportStatus::Completed)
        ->and($first->attempts)->toBe(1);

    // Retrying something that did not fail changes nothing.
    $this->exports->retry(WorkflowScenario::actor($hr), $hr, $first->id);
    Queue::assertPushed(GenerateReportExport::class, 1);

    // A different format or filter is a different export; a completed one does not block a new request.
    expect(requestExport($this, $hr)->id)->not->toBe($first->id)
        ->and(requestExport($this, $hr, 'scorecards', 'xlsx', [...$this->filters, 'level' => 'team'])->id)->not->toBe($first->id);

    // Too many exports in flight are refused.
    requestExport($this, $hr, 'kpi_lines');
    expect(AccessFixtures::violatedRule(fn () => requestExport($this, $hr, 'calculations')))->toBe('too_many_exports');
});

test('a failed generation is retried by the queue, ends with a safe reason, and can be retried by its owner', function (): void {
    Queue::fake();
    $hr = $this->scenario->hr();
    $export = requestExport($this, $hr);

    // Storage is unavailable: the job fails and stays retryable.
    Config::set('reports.disk', 'disk-yang-tidak-ada');
    $broken = app(ExportService::class);

    expect(fn () => $broken->generate($export->id))->toThrow(InvalidArgumentException::class)
        ->and($export->refresh()->status)->toBe(ExportStatus::Queued)
        ->and($export->attempts)->toBe(1);

    // The queue gives up: a safe reason, no internals.
    (new GenerateReportExport($export->id))->failed(new RuntimeException('s3: access denied for key AKIA...'));

    expect($export->refresh()->status)->toBe(ExportStatus::Failed)
        ->and($export->failure_code)->toBe('generation_failed')
        ->and($export->failureMessage())->not->toContain('AKIA')
        ->and(json_encode(AuditLog::query()->where('action', 'report_export.failed')->firstOrFail()->after_values))->not->toContain('AKIA')
        ->and(glob(storage_path('app/private/report-exports-tmp/*')))->toBe([]);

    // Only the owner retries; the retry completes once storage is back.
    expect(fn () => $this->exports->retry(WorkflowScenario::actor($this->scenario->manager), $this->scenario->manager, $export->id))->toThrow(AuthorizationException::class);

    $this->exports->retry(WorkflowScenario::actor($hr), $hr, $export->id);
    $this->exports->retry(WorkflowScenario::actor($hr), $hr, $export->id);
    Queue::assertPushed(GenerateReportExport::class, 2);

    $this->exports->generate($export->id);

    expect($export->refresh()->status)->toBe(ExportStatus::Completed)
        ->and($export->attempts)->toBe(2)
        ->and($export->failure_code)->toBeNull();
});

test('a large export is streamed without holding the rows in memory', function (): void {
    $rows = 50000;
    $generator = (function () use ($rows) {
        for ($index = 1; $index <= $rows; $index++) {
            yield ['P-2026-11', 'employee', "E-{$index}", "Pegawai {$index}", 'PROD-WOVEN', 'team', 'T@1', 'Lengkap', '1', '95.5000', 'Band uji 3', '95.5000', 'Band uji 3', 'tidak', 'Menunggu review', '1', 'test-only@1', str_repeat('a', 64)];
        }
    })();

    $path = storage_path('framework/testing/'.uniqid('large-', true).'.xlsx');
    @mkdir(dirname($path), 0777, true);
    $metadata = new ExportMetadata('Uji volume', 'volume', [], null, 'Asia/Jakarta', CarbonImmutable::now(), 'Uji', 'Sintetis', Classification::Internal, 'TEST');
    $progress = [];

    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $started = hrtime(true);

    $written = (new XlsxExportWriter)->write($path, $metadata, array_fill(0, 18, 'Kolom'), $generator, function (int $count) use (&$progress): void {
        $progress[] = $count;
    }, 200000);

    $seconds = (hrtime(true) - $started) / 1e9;
    $peakMb = (memory_get_peak_usage() - $before) / 1048576;
    $sizeMb = filesize($path) / 1048576;
    @unlink($path);

    fwrite(STDERR, sprintf("\n[export volume] rows=%d seconds=%.2f peak_memory_mb=%.1f file_mb=%.1f\n", $written, $seconds, $peakMb, $sizeMb));

    expect($written)->toBe($rows)
        ->and(count($progress))->toBeGreaterThan(90)
        // Fifty thousand rows of eighteen columns would need far more than this if they were held in memory.
        ->and($peakMb)->toBeLessThan(48.0);

    // The row limit stops a runaway report.
    expect(fn () => (new XlsxExportWriter)->write($path, $metadata, ['A'], (fn () => yield from [['1'], ['2'], ['3']])(), fn () => null, 2))->toThrow(TooManyRows::class);
    @unlink($path);
});

test('the report page previews, exports, and offers downloads only to accounts with reports', function (): void {
    $hr = $this->scenario->hr();
    $period = $this->scenario->period->public_id;

    $this->actingAs($this->scenario->scorecards->staff)->get(route('reports.index'))->assertForbidden();
    $this->actingAs(AccessFixtures::userWithRole(Role::SystemAdmin))->get(route('reports.index'))->assertForbidden();
    $this->actingAs($this->scenario->manager)->get(route('reports.index'))->assertOk()->assertDontSee('Laporan audit');

    Livewire::actingAs($hr)->test('pages::reports.index')
        ->assertSee('Laporan audit')
        ->set('report', 'scorecards')
        ->call('show')
        ->assertHasErrors('action')
        ->set('period', $period)
        ->set('unit', 'PROD')
        ->call('show')
        ->assertHasNoErrors()
        ->assertSee('W-001')
        ->assertDontSee('AR-001')
        ->call('export', 'xlsx')
        ->assertHasNoErrors()
        ->assertSee('Selesai')
        ->call('export', 'docx')
        ->assertHasErrors('action');

    $export = ReportExport::query()->where('user_id', $hr->id)->firstOrFail();
    expect($export->filters)->toBe(['period' => $period, 'unit' => 'PROD']);

    Livewire::actingAs($hr)->test('pages::reports.index')->call('download', $export->public_id)->assertRedirectContains('/reports/exports/'.$export->public_id);

    // A manager cannot touch another account's export or preview outside the scope.
    Livewire::actingAs($this->scenario->manager)->test('pages::reports.index')->call('download', $export->public_id)->assertNotFound();
    Livewire::actingAs($this->scenario->manager)->test('pages::reports.index')->call('retry', $export->public_id)->assertNotFound();
    Livewire::actingAs($this->scenario->manager)->test('pages::reports.index')
        ->set('report', 'scorecards')
        ->set('period', $period)
        ->set('unit', 'ACC-AR')
        ->call('show')
        ->assertForbidden();
    Livewire::actingAs($this->scenario->manager)->test('pages::reports.index')
        ->set('report', 'audit_log')
        ->set('from', AccessFixtures::TODAY)
        ->set('to', AccessFixtures::TODAY)
        ->call('export', 'xlsx')
        ->assertForbidden();
});

test('an export left running by a dead worker is failed by housekeeping and can be retried', function (): void {
    Queue::fake();
    $hr = $this->scenario->hr();
    $export = requestExport($this, $hr);

    // A worker claimed the export and was killed: it stays running, with a temp file.
    ReportExport::query()->whereKey($export->id)->update(['status' => ExportStatus::Running->value, 'started_at' => CarbonImmutable::now(), 'attempts' => 1]);
    $leftover = storage_path('app/private/report-exports-tmp/'.$export->public_id.'.xlsx');
    @mkdir(dirname($leftover), 0777, true);
    file_put_contents($leftover, 'partial');

    // A redelivered job does not touch a running export, and a fresh run is not stale yet.
    $this->exports->generate($export->id);
    $this->artisan('reports:purge-expired-exports')->expectsOutputToContain('Stale running exports failed: 0')->assertSuccessful();
    expect($export->refresh()->status)->toBe(ExportStatus::Running);

    $this->travelTo(CarbonImmutable::now()->addMinutes(11));
    touch($leftover, CarbonImmutable::now()->subMinutes(11)->getTimestamp());
    $this->artisan('reports:purge-expired-exports')->expectsOutputToContain('Stale running exports failed: 1')->assertSuccessful();

    expect($export->refresh()->status)->toBe(ExportStatus::Failed)
        ->and($export->failure_code)->toBe('generation_failed')
        ->and(file_exists($leftover))->toBeFalse();

    // The owner's quota is free again and the retry completes.
    $this->exports->retry(WorkflowScenario::actor($hr), $hr, $export->id);
    $this->exports->generate($export->id);

    expect($export->refresh()->status)->toBe(ExportStatus::Completed);
});

test('a retry while the same request is already in flight returns that export', function (): void {
    Queue::fake();
    $hr = $this->scenario->hr();
    $failed = requestExport($this, $hr);
    ReportExport::query()->whereKey($failed->id)->update(['status' => ExportStatus::Failed->value, 'failure_code' => 'generation_failed']);

    $again = requestExport($this, $hr);
    $returned = $this->exports->retry(WorkflowScenario::actor($hr), $hr, $failed->id);

    expect($again->id)->not->toBe($failed->id)
        ->and($returned->id)->toBe($again->id)
        ->and($failed->refresh()->status)->toBe(ExportStatus::Failed);
});

test('exports are never stored on a public disk', function (): void {
    Config::set('reports.disk', 'public');

    expect(fn () => app(ExportService::class))->toThrow(LogicException::class);
});
