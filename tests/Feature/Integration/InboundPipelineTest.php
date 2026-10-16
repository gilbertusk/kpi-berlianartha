<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Integration\Application\Adapters\ProductionBatchAdapter;
use App\Modules\Integration\Application\Connection;
use App\Modules\Integration\Application\InboundAdapter;
use App\Modules\Integration\Application\InboundRunner;
use App\Modules\Integration\Application\StagedTarget;
use App\Modules\Integration\Domain\Manifest;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationException;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationRun;
use App\Modules\Production\Application\ProductionImportService;
use App\Modules\Production\Application\ProductionRowParser;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionRecord;
use App\Modules\Shared\Application\Audit\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\IntegrationHarness as Inbox;
use Tests\Support\ProductionHarness as Harness;

/*
 * Inbound pipeline: receive → authenticate and checksum → schema and
 * semantic validation → reconcile → commit (when approved) → archive or
 * quarantine; replay, retries, dead letters, isolation, and credentials.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    Harness::useCalendar();
    Inbox::fake();
    $this->svc = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    $this->stockSvc = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WAREHOUSE');
    $this->travelTo(CarbonImmutable::parse(Harness::NOW, 'UTC'));
    $this->header = implode(',', ProductionRowParser::COLUMNS['production']);
    $this->single = ['output,EV-1,WO-1,,,M-01,,,2026-10-13 08:00,,pcs,100.5,2,1,,DF,', 'output,EV-2,WO-1,,,M-01,,,2026-10-13 09:00,,pcs,50,0,0,,,'];
    $this->controls = ['control_totals' => ['quantity' => '150.5', 'reject' => '2', 'rework' => '1']];
    $this->runner = app(InboundRunner::class);
});

function runOnce(object $test, ?string $only = 'mes-production'): IntegrationRun
{
    $result = $test->runner->run($only);

    expect($result['runs'])->toHaveCount(1);

    return $result['runs'][0]->refresh();
}

test('a signed file is authenticated, staged through the module import, reconciled, and archived', function (): void {
    $key = Inbox::enable('mes-production', $this->svc);
    Inbox::drop('mes-production', 'production-2026-10-13.csv', $this->header, $this->single, $key, $this->controls);
    $run = runOnce($this);
    $batch = ProductionBatch::query()->sole();

    expect($run->status)->toBe(IntegrationRun::STAGED)
        ->and([$run->row_count, $run->accepted_count, $run->rejected_count, $run->duplicate_count])->toBe([2, 2, 0, 0])
        ->and($run->reconciled)->toBeTrue()
        ->and([$run->target_type, $run->target_public_id])->toBe(['production_batch', $batch->public_id])
        ->and($run->watermark?->toIso8601String())->toBe('2026-10-14T02:30:00+00:00')
        ->and($run->manifest)->not->toHaveKey('signature')
        ->and($batch->status)->toBe(ProductionBatch::STAGED)         // no auto-commit without approval
        ->and($batch->staged_by_user_id)->toBe($this->svc->id)
        ->and(Inbox::files('inbox'))->toBe([])
        ->and(Inbox::files('archive'))->toHaveCount(2);
});

test('auto-commit commits only a reconciled file; a difference stays staged as an exception', function (): void {
    $key = Inbox::enable('mes-production', $this->svc, ['auto_commit' => true]);
    Inbox::drop('mes-production', 'a.csv', $this->header, $this->single, $key, $this->controls);
    $good = runOnce($this);

    Inbox::drop('mes-production', 'b.csv', $this->header, ['output,EV-9,WO-2,,,M-01,,,2026-10-12 08:00,,pcs,10,,,,,'], $key, ['coverage_date' => '2026-10-12', 'control_totals' => ['quantity' => '11', 'reject' => '0', 'rework' => '0']]);
    $off = runOnce($this);

    expect($good->status)->toBe(IntegrationRun::COMMITTED)
        ->and($good->reconciliation['quantity'] ?? null)->toBe(['manifest' => '150.5', 'batch' => '150.500', 'difference' => '0.000'])
        ->and(ProductionBatch::query()->where('public_id', $good->target_public_id)->value('status'))->toBe(ProductionBatch::COMMITTED)
        ->and($off->status)->toBe(IntegrationRun::STAGED)
        ->and($off->reconciled)->toBeFalse()
        ->and($off->reconciliation['quantity']['difference'] ?? null)->toBe('-1.000')
        ->and(IntegrationException::query()->where('integration_run_id', $off->id)->value('kind'))->toBe('reconciliation_difference');
});

test('a replayed file never duplicates data', function (): void {
    $key = Inbox::enable('mes-production', $this->svc, ['auto_commit' => true]);
    Inbox::drop('mes-production', 'a.csv', $this->header, $this->single, $key, $this->controls);
    $first = runOnce($this);
    Inbox::drop('mes-production', 'a.csv', $this->header, $this->single, $key, $this->controls);
    $replay = runOnce($this);

    expect($replay->status)->toBe(IntegrationRun::DUPLICATE)
        ->and($replay->duplicate_count)->toBe(2)
        ->and($replay->target_public_id)->toBe($first->target_public_id)
        ->and(ProductionBatch::query()->count())->toBe(1)
        ->and(ProductionRecord::query()->count())->toBe(2);
});

test('an unauthentic, altered, or malformed delivery is quarantined and nothing is staged', function (Closure $drop, string $kind, ?string $register = null): void {
    $key = Inbox::enable('mes-production', $this->svc);
    $drop($this, $key);
    $run = runOnce($this);

    expect($run->status)->toBe(IntegrationRun::QUARANTINED)
        ->and($run->error_code)->toBe($kind)
        ->and(IntegrationException::query()->where('integration_run_id', $run->id)->value('kind'))->toBe($register ?? $kind)
        ->and(ProductionBatch::query()->count())->toBe(0)
        ->and(Inbox::files('quarantine'))->toHaveCount(2)
        ->and(Inbox::files('inbox'))->toBe([]);
})->with([
    'signed with another key' => [fn (object $test, string $key) => Inbox::drop('mes-production', 'a.csv', $test->header, $test->single, 'another-key'), 'signature_invalid'],
    'manifest changed after signing' => [function (object $test, string $key): void {
        $manifest = Inbox::drop('mes-production', 'a.csv', $test->header, $test->single, $key);
        Storage::disk(Inbox::DISK)->put('integration/inbox/mes-production/a.csv.manifest.json', (string) json_encode([...$manifest, 'rows' => 99]));
    }, 'signature_invalid'],
    'file changed after signing' => [function (object $test, string $key): void {
        Inbox::drop('mes-production', 'a.csv', $test->header, $test->single, $key);
        Storage::disk(Inbox::DISK)->append('integration/inbox/mes-production/a.csv', 'output,EV-3,WO-1,,,M-01,,,2026-10-13 10:00,,pcs,1,,,,,');
    }, 'checksum_mismatch'],
    'manifest for another connection' => [fn (object $test, string $key) => Inbox::drop('mes-production', 'a.csv', $test->header, $test->single, $key, ['connection' => 'wms-stock']), 'manifest_invalid'],
    'manifest for another file' => [fn (object $test, string $key) => Inbox::drop('mes-production', 'a.csv', $test->header, $test->single, $key, ['file' => 'b.csv']), 'manifest_invalid'],
    'unknown contract' => [fn (object $test, string $key) => Inbox::drop('mes-production', 'a.csv', $test->header, $test->single, $key, ['contract' => 'vendor-v9']), 'manifest_invalid'],
    'control total as float' => [fn (object $test, string $key) => Inbox::drop('mes-production', 'a.csv', $test->header, $test->single, $key, ['control_totals' => ['quantity' => 150.5]]), 'manifest_invalid'],
    'not JSON' => [function (object $test, string $key): void {
        Inbox::drop('mes-production', 'a.csv', $test->header, $test->single, $key);
        Storage::disk(Inbox::DISK)->put('integration/inbox/mes-production/a.csv.manifest.json', '{"contract":');
    }, 'manifest_invalid'],
    'missing required column' => [fn (object $test, string $key) => Inbox::drop('mes-production', 'a.csv', 'record_type,work_order', ['output,WO-1'], $key), 'invalid_file', 'rejected_by_target'],
    'empty file' => [fn (object $test, string $key) => Inbox::drop('mes-production', 'a.csv', '', [], $key, content: ''), 'invalid_file'],
    'vendor header that maps to nothing' => [fn (object $test, string $key) => Inbox::drop('mes-production', 'a.csv', 'Qty (pcs),"WO, number"', ['1,WO-1'], $key), 'invalid_file'],
    'unit outside the run-as account' => [fn (object $test, string $key) => Inbox::drop('mes-production', 'a.csv', $test->header, $test->single, $key, ['unit' => 'PROD-FLEXO']), 'not_authorized'],
]);

test('rows with errors are staged with nothing saved, registered, and never auto-committed', function (): void {
    $key = Inbox::enable('mes-production', $this->svc, ['auto_commit' => true]);
    Inbox::drop('mes-production', 'a.csv', $this->header, [...$this->single, 'output,EV-3,WO-1,,,M-01,,,2026-10-13 10:00,,pcs,-1,,,,,'], $key);
    $run = runOnce($this);

    expect($run->status)->toBe(IntegrationRun::STAGED)
        ->and([$run->row_count, $run->accepted_count, $run->rejected_count])->toBe([3, 0, 1])
        ->and(ProductionRecord::query()->count())->toBe(0)
        ->and(ProductionBatch::query()->sole()->status)->toBe(ProductionBatch::STAGED)
        ->and(IntegrationException::query()->where('integration_run_id', $run->id)->value('kind'))->toBe('rows_rejected');
});

test('a failing system is retried a bounded number of times, then dead-lettered and recovered by hand', function (): void {
    $key = Inbox::enable('mes-production', $this->svc);
    $secret = 'password=hunter2-'.$key;
    app()->bind('integration.adapter.production', fn () => new class($secret) implements InboundAdapter
    {
        public function __construct(private string $secret) {}

        public function stage(Connection $connection, Manifest $manifest, string $path, User $runAs, Actor $actor): StagedTarget
        {
            throw new RuntimeException("connection refused: {$this->secret}");
        }

        public function commit(StagedTarget $target, User $runAs, Actor $actor): void {}
    });
    Inbox::drop('mes-production', 'a.csv', $this->header, $this->single, $key);

    $first = runOnce($this);

    expect([$first->status, $first->attempts])->toBe([IntegrationRun::FAILED, 1])
        ->and($this->runner->run('mes-production')['runs'])->toBe([]);   // waits for its next attempt

    $this->travel(5)->minutes();
    $second = runOnce($this);
    $this->travel(10)->minutes();
    $third = runOnce($this);

    expect($second->id)->toBe($first->id)
        ->and([$third->status, $third->attempts])->toBe([IntegrationRun::DEAD_LETTER, 3])
        ->and(IntegrationException::query()->where('kind', 'dead_letter')->count())->toBe(1)
        ->and(Inbox::files('inbox'))->toHaveCount(2);                      // the file waits for recovery

    $this->travel(1)->hours();

    expect($this->runner->run('mes-production')['runs'])->toBe([]);       // a dead letter is never retried by itself

    app()->bind('integration.adapter.production', ProductionBatchAdapter::class);
    $recovered = $this->runner->retry($third);

    expect([$recovered->status, $recovered->attempts])->toBe([IntegrationRun::STAGED, 4])
        ->and(ProductionBatch::query()->count())->toBe(1);

    // Credentials and exception messages never reach the records.
    $stored = json_encode([IntegrationRun::query()->get()->toArray(), IntegrationException::query()->get()->toArray(), DB::table('audit_logs')->get()->toArray()]);

    expect($stored)->not->toContain($key)
        ->and($stored)->not->toContain('hunter2');
});

test('one failing connection does not stop another', function (): void {
    $key = Inbox::enable('mes-production', $this->svc);
    $stockKey = Inbox::enable('wms-stock', $this->stockSvc);
    app()->bind('integration.adapter.production', fn () => new class implements InboundAdapter
    {
        public function stage(Connection $connection, Manifest $manifest, string $path, User $runAs, Actor $actor): StagedTarget
        {
            if ($connection->key === 'mes-production') {
                throw new RuntimeException('down');
            }

            return app(ProductionBatchAdapter::class)->stage($connection, $manifest, $path, $runAs, $actor);
        }

        public function commit(StagedTarget $target, User $runAs, Actor $actor): void {}
    });
    Inbox::drop('mes-production', 'a.csv', $this->header, $this->single, $key);
    Inbox::drop('wms-stock', 'stock.csv', implode(',', ProductionRowParser::COLUMNS['warehouse']), ['RM-A1,raw_material,YARN-1,L-1,kg,100'], $stockKey, ['unit' => 'PROD-WAREHOUSE', 'source_system' => 'wms-main', 'control_totals' => ['quantity' => '100']]);

    $runs = collect($this->runner->run()['runs'])->keyBy('connection');

    expect($runs['mes-production']->status)->toBe(IntegrationRun::FAILED)
        ->and($runs['wms-stock']->status)->toBe(IntegrationRun::STAGED)
        ->and($runs['wms-stock']->reconciled)->toBeTrue();
});

test('vendor columns are mapped to the internal contract before the module sees them', function (): void {
    $key = Inbox::enable('mes-production', $this->svc, ['column_map' => ['TYPE' => 'record_type', 'EVENT' => 'event_id', 'WO_NO' => 'work_order', 'MACHINE' => 'machine_code', 'START' => 'started_at', 'UOM' => 'unit', 'QTY_GOOD' => 'good_qty']]);
    Inbox::drop('mes-production', 'vendor.csv', 'TYPE;EVENT;WO_NO;MACHINE;START;UOM;QTY_GOOD', ['output;EV-1;WO-1;M-01;2026-10-13 08:00;pcs;12'], $key);
    $run = runOnce($this);

    expect($run->status)->toBe(IntegrationRun::STAGED)
        ->and(ProductionRecord::query()->sole()->only(['work_order', 'machine_code', 'quantity']))->toBe(['work_order' => 'WO-1', 'machine_code' => 'M-01', 'quantity' => '12.000']);
});

test('receivables pass through their own adapter with their control totals', function (): void {
    $ar = AccessFixtures::userWithRole(Role::TeamLeader, 'ACC-AR', '2026-10-14');
    $key = Inbox::enable('erp-receivables', $ar, ['auto_commit' => true]);
    $header = 'record_type,document_number,related_document,customer_code,customer_name,document_date,due_date,currency,amount,status,status_reason,collection_owner,next_action,next_action_date';
    Inbox::drop('erp-receivables', 'receivables.csv', $header, [
        'invoice,INV-1,,C-1,X,2026-10-01,2026-10-30,IDR,1000.50,,,,,',
        'allocation,PAY-1,INV-1,,,2026-10-05,,IDR,500.25,,,,,',
    ], $key, ['unit' => 'ACC-AR', 'coverage_date' => '2026-10-14', 'source_system' => 'erp-accounting', 'control_totals' => ['invoice' => '1000.50', 'allocation' => '500.25']]);
    $run = runOnce($this, 'erp-receivables');

    expect($run->status)->toBe(IntegrationRun::COMMITTED)
        ->and($run->target_type)->toBe('ar_snapshot')
        ->and($run->reconciled)->toBeTrue();
});

test('connections that are not approved, keyed, or staffed are skipped with the reason', function (): void {
    Inbox::drop('mes-production', 'a.csv', $this->header, $this->single, 'k');
    $skipped = $this->runner->run()['skipped'];

    expect($skipped['mes-production'])->toBe('Koneksi belum diaktifkan.')
        ->and(Inbox::files('inbox'))->toHaveCount(2);

    Inbox::enable('mes-production', $this->svc, ['hmac_key' => null]);

    expect($this->runner->run()['skipped']['mes-production'])->toContain('Kunci tanda tangan');

    Inbox::enable('mes-production', $this->svc, ['run_as' => 'nobody@fixtures.example.test']);

    expect($this->runner->run()['skipped']['mes-production'])->toContain('run-as');
});

test('a declared control total missing from the manifest is a difference and blocks auto-commit', function (): void {
    $key = Inbox::enable('mes-production', $this->svc, ['auto_commit' => true]);
    Inbox::drop('mes-production', 'a.csv', $this->header, Harness::wovenDay(), $key);
    $run = runOnce($this);

    expect($run->status)->toBe(IntegrationRun::STAGED)
        ->and($run->reconciled)->toBeFalse()
        ->and($run->reconciliation['quantity']['difference'] ?? null)->toBe('tidak ada di manifest')
        ->and(ProductionBatch::query()->sole()->status)->toBe(ProductionBatch::STAGED);
});

test('the same snapshot content on another date, or after a rejection, is loaded again', function (): void {
    $key = Inbox::enable('wms-stock', $this->stockSvc);
    $header = implode(',', ProductionRowParser::COLUMNS['warehouse']);
    $stock = ['RM-A1,raw_material,YARN-1,L-1,kg,100'];
    $manifest = ['unit' => 'PROD-WAREHOUSE', 'source_system' => 'wms-main', 'control_totals' => ['quantity' => '100']];

    Inbox::drop('wms-stock', 'stock-13.csv', $header, $stock, $key, [...$manifest, 'coverage_date' => '2026-10-13']);
    $monday = runOnce($this, 'wms-stock');
    Inbox::drop('wms-stock', 'stock-14.csv', $header, $stock, $key, [...$manifest, 'coverage_date' => '2026-10-14']);
    $tuesday = runOnce($this, 'wms-stock');

    expect([$monday->status, $tuesday->status])->toBe([IntegrationRun::STAGED, IntegrationRun::STAGED])
        ->and($tuesday->target_public_id)->not->toBe($monday->target_public_id);

    app(ProductionImportService::class)->reject(Harness::actor($this->stockSvc), $this->stockSvc, ProductionBatch::query()->where('public_id', $tuesday->target_public_id)->value('id'), 'Ekstrak salah');
    Inbox::drop('wms-stock', 'stock-14b.csv', $header, $stock, $key, [...$manifest, 'coverage_date' => '2026-10-14']);
    $again = runOnce($this, 'wms-stock');

    expect($again->status)->toBe(IntegrationRun::STAGED)
        ->and(ProductionBatch::query()->where('public_id', $again->target_public_id)->value('version'))->toBe(2);
});

test('content the module already holds is recorded as a duplicate and never committed on someone else\'s behalf', function (): void {
    $key = Inbox::enable('mes-production', $this->svc, ['auto_commit' => true]);
    $human = Harness::stage($this->svc, BatchKind::Production, $this->single, 'PROD-WOVEN', '2026-10-13', source: 'mes-woven');
    Inbox::drop('mes-production', 'a.csv', $this->header, $this->single, $key, $this->controls);
    $run = runOnce($this);

    expect($run->status)->toBe(IntegrationRun::DUPLICATE)
        ->and($run->target_public_id)->toBe($human->public_id)
        ->and($human->refresh()->status)->toBe(ProductionBatch::STAGED);
});

test('an extract older than one already accepted for the same date is refused', function (): void {
    $key = Inbox::enable('mes-production', $this->svc);
    Inbox::drop('mes-production', 'new.csv', $this->header, $this->single, $key, [...$this->controls, 'source_extracted_at' => '2026-10-14 09:30']);
    runOnce($this);
    Inbox::drop('mes-production', 'old.csv', $this->header, [...$this->single, 'output,EV-3,WO-1,,,M-01,,,2026-10-13 10:00,,pcs,1,,,,,'], $key, ['source_extracted_at' => '2026-10-14 06:00']);
    $old = runOnce($this);

    expect([$old->status, $old->error_code])->toBe([IntegrationRun::QUARANTINED, 'stale_extract'])
        ->and(ProductionBatch::query()->count())->toBe(1);
});

test('inspection files reconcile their reject total without the batch refusing it', function (): void {
    $qc = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-QC', '2026-10-14');
    $key = Inbox::enable('qms-inspection', $qc, ['auto_commit' => true]);
    Inbox::drop('qms-inspection', 'inspection.csv', implode(',', ProductionRowParser::COLUMNS['inspection']), ['INS-1,in_process,2026-10-14 08:00,WO-1,P-1,L-1,,pcs,500,480,20,DF-1'], $key, ['unit' => 'PROD-QC', 'coverage_date' => '2026-10-14', 'source_system' => 'qms-main', 'control_totals' => ['quantity' => '500', 'reject' => '20']]);
    $run = runOnce($this, 'qms-inspection');

    expect($run->status)->toBe(IntegrationRun::COMMITTED)
        ->and($run->reconciliation['reject'] ?? null)->toBe(['manifest' => '20', 'batch' => '20.000', 'difference' => '0.000']);
});

test('size limits, crashed runs, concurrent processes, and short keys are handled before any data is read', function (): void {
    $key = Inbox::enable('mes-production', $this->svc);
    config(['integrations.max_file_bytes' => 64]);
    Inbox::drop('mes-production', 'big.csv', $this->header, $this->single, $key, $this->controls);
    $big = runOnce($this);

    expect([$big->status, $big->error_code])->toBe([IntegrationRun::QUARANTINED, 'file_too_large']);

    // A run a crash left in `received` counts its attempts and ends as a dead letter.
    config(['integrations.max_file_bytes' => 20 * 1024 * 1024]);
    Inbox::drop('mes-production', 'crash.csv', $this->header, $this->single, $key, $this->controls);
    $crashed = IntegrationRun::query()->create(['connection' => 'mes-production', 'mode' => 'inbound', 'file_name' => 'crash.csv', 'status' => IntegrationRun::RECEIVED, 'attempts' => 3]);
    $dead = runOnce($this);

    expect($dead->id)->toBe($crashed->id)
        ->and($dead->status)->toBe(IntegrationRun::DEAD_LETTER);

    // Another process holds the connection.
    $lock = Cache::lock('integration-run:mes-production', 60);
    $lock->get();

    expect($this->runner->run('mes-production')['skipped']['mes-production'] ?? null)->toBe('Koneksi sedang diproses oleh proses lain.');

    $lock->release();
    Inbox::enable('mes-production', $this->svc, ['hmac_key' => 'short']);

    expect($this->runner->run('mes-production')['skipped']['mes-production'] ?? '')->toContain('terlalu pendek');
});
