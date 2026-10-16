<?php

declare(strict_types=1);

use App\Console\Commands\Operations\SnapshotPath;
use App\Providers\ObservabilityServiceProvider;
use App\Support\Operations\Heartbeat;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;

/*
 * Step 18 signals: readiness classes, metrics snapshot, slow queries, and
 * the storage snapshot used by backups and restore checks.
 */

test('readiness separates critical dependencies from optional signals', function (): void {
    Storage::fake('readiness-test');

    // No heartbeat yet: optional signals warn, the command still passes.
    $this->artisan('ops:readiness', ['--disk' => 'readiness-test'])
        ->expectsOutputToContain('scheduler_heartbeat')
        ->expectsOutputToContain('WARN')
        ->assertSuccessful();

    $this->artisan('ops:readiness', ['--disk' => 'readiness-test', '--strict' => true])->assertFailed();

    app(Heartbeat::class)->beat('scheduler');
    app(Heartbeat::class)->beat('worker');

    $this->artisan('ops:readiness', ['--disk' => 'readiness-test', '--strict' => true])->assertSuccessful();

    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

    $this->artisan('ops:readiness', ['--disk' => 'readiness-test', '--json' => true])
        ->expectsOutputToContain('"check":"failed_jobs","critical":false,"status":"WARN"')
        ->assertSuccessful();
});

test('metrics are counts and ages only, in JSON or Prometheus text', function (): void {
    app(Heartbeat::class)->beat('scheduler');
    DB::table('outbox_messages')->insert([
        'public_id' => (string) Str::ulid(), 'event_type' => 'x', 'event_version' => 1, 'aggregate_type' => 'x', 'aggregate_id' => '1',
        'payload' => '{"secret":"sentinel-payload"}', 'headers' => '{}', 'payload_hash' => hash('sha256', 'x'), 'attempts' => 5,
        'occurred_at' => now()->subMinutes(5), 'available_at' => now(), 'failed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('ops:metrics', ['--format' => 'prometheus'])
        ->expectsOutputToContain('kpi_outbox_dead_letter 1')
        ->expectsOutputToContain('kpi_failed_jobs_total 0')
        ->expectsOutputToContain('kpi_scheduler_heartbeat_age_seconds 0')
        ->doesntExpectOutputToContain('sentinel-payload')
        ->assertSuccessful();

    $this->artisan('ops:metrics')->expectsOutputToContain('"kpi_integration_exceptions_open":0')->assertSuccessful();
});

test('slow queries are logged without their bindings', function (): void {
    config(['observability.slow_query_ms' => 1]);
    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);
    app()->register(ObservabilityServiceProvider::class, true);

    DB::select('WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 200000) SELECT count(*) AS c FROM n WHERE ? = ?', ['sentinel-binding', 'sentinel-binding']);

    $slow = collect($handler->getRecords())->first(fn ($record): bool => $record->message === 'db.slow_query');

    expect($slow)->not->toBeNull()
        ->and(json_encode($slow?->context))->not->toContain('sentinel-binding');
});

test('a storage snapshot verifies against its manifest and the object metadata, and detects tampering', function (): void {
    Storage::fake('s3');
    Storage::disk('s3')->put('evidence/a.txt', 'alpha');
    Storage::disk('s3')->put('evidence/b.txt', 'beta');
    DB::table('stored_object_metadata')->insert(collect(['a' => 'alpha', 'b' => 'beta'])->map(fn (string $content, string $name): array => [
        'public_id' => (string) Str::ulid(), 'disk' => 's3', 'path' => "evidence/{$name}.txt", 'original_name' => "{$name}.txt", 'content_type' => 'text/plain',
        'byte_size' => strlen($content), 'checksum_algorithm' => 'sha256', 'checksum' => hash('sha256', $content), 'classification' => 'internal',
        'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ])->values()->all());
    $dir = storage_path('framework/testing/snapshot-'.Str::random(8));

    $this->artisan('ops:storage-snapshot', ['directory' => $dir])->expectsOutputToContain('2 objek disalin')->assertSuccessful();
    $this->artisan('ops:storage-verify', ['directory' => $dir])->assertSuccessful();

    file_put_contents("{$dir}/objects/evidence/a.txt", 'tampered');

    $this->artisan('ops:storage-verify', ['directory' => $dir])->assertFailed();
    $this->artisan('ops:storage-snapshot', ['directory' => $dir])->assertFailed();     // never into a used directory

    File::deleteDirectory($dir);
});

test('the observability provider is registered and a failed job is logged without its message', function (): void {
    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);
    $job = new SyncJob(app(), (string) json_encode(['displayName' => 'App\Jobs\Example', 'job' => 'x', 'data' => []]), 'sync', 'default');

    event(new JobFailed('sync', $job, new RuntimeException('password=sentinel-job-secret')));

    $entry = collect($handler->getRecords())->first(fn ($record): bool => $record->message === 'queue.job_failed');

    expect(app()->getProviders(ObservabilityServiceProvider::class))->not->toBeEmpty()
        ->and($entry?->context)->toMatchArray(['job' => 'App\Jobs\Example', 'exception' => RuntimeException::class])
        ->and(json_encode($entry?->context))->not->toContain('sentinel-job-secret');
});

test('a metric whose source fails is reported as unavailable instead of failing the command', function (): void {
    Schema::drop('integration_exceptions');

    $this->artisan('ops:metrics', ['--format' => 'prometheus'])
        ->expectsOutputToContain('# kpi_integration_exceptions_open unavailable')
        ->expectsOutputToContain('kpi_failed_jobs_total 0')
        ->assertSuccessful();
});

test('snapshot paths must stay inside the snapshot', function (string $path, bool $safe): void {
    expect(SnapshotPath::isSafe($path))->toBe($safe);
})->with([
    ['evidence/2026/a.txt', true],
    ['../etc/passwd', false],
    ['evidence/../../x', false],
    ['/abs/path', false],
    ['C:/windows', false],
    ['evidence\..\x', false],
    ["evidence/a\0.txt", false],
    ['evidence//a.txt', false],
    ['', false],
]);

test('a manifest entry outside the snapshot counts as corrupt', function (): void {
    $dir = storage_path('framework/testing/snapshot-'.Str::random(8));
    File::ensureDirectoryExists("{$dir}/objects");
    file_put_contents("{$dir}/outside.txt", 'x');
    file_put_contents("{$dir}/MANIFEST.json", (string) json_encode(['disk' => 's3', 'objects' => [['path' => '../outside.txt', 'sha256' => hash('sha256', 'x')]]]));

    $this->artisan('ops:storage-verify', ['directory' => $dir])->expectsOutputToContain('Objek rusak')->assertFailed();

    File::deleteDirectory($dir);
});

test('the security gate warns about a wildcard proxy and a previous integration key still accepted', function (): void {
    config([
        'observability.trusted_proxies' => '*',
        'integrations.connections.erp-receivables.hmac_key_previous' => str_repeat('k', 40),
    ]);

    expect(Artisan::call('ops:security-check', ['--json' => true]))->toBe(0);
    $checks = collect(json_decode(Artisan::output(), true)['checks'])->pluck('status', 'check');

    expect($checks['Proxy tepercaya berupa IP/CIDR, bukan *'])->toBe('WARN')
        ->and($checks['Tidak ada kunci integrasi lama yang masih diterima (..._HMAC_KEY_PREVIOUS)'])->toBe('WARN');
});
