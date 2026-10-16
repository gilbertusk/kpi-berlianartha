<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Modules\Integration\Application\IntegrationQuery;
use App\Support\Operations\Heartbeat;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Internal (CLI-only) readiness check for operators and container tooling.
 *
 * Unlike the public GET /health liveness probe, this verifies dependencies.
 * Critical checks (database, cache, object storage, queue connection) decide
 * the exit code; optional signals (process heartbeats, failed jobs, outbox
 * dead letters, integration freshness) are reported as WARN and fail only
 * with --strict. Output names the failing check and exception type only; the
 * full exception goes to the (redacted) application log.
 */
#[Signature('ops:readiness {--disk=s3 : Filesystem disk used for the storage round-trip} {--connection= : Database connection (default connection when omitted)} {--strict : Optional signals fail too} {--json : Machine-readable output}')]
#[Description('Verify database, cache, storage, and queue, and report process and integration signals')]
final class ReadinessCommand extends Command
{
    private const HEARTBEAT_MAX_AGE = 150;

    public function handle(Repository $cache, Heartbeat $heartbeat): int
    {
        $results = [
            $this->check('database', true, fn () => DB::connection($this->databaseConnection())->select('select 1 as ok') !== [] ? null : 'Tidak ada hasil.'),
            $this->check('cache', true, fn () => $this->cacheRoundTrip($cache)),
            $this->check('storage', true, fn () => $this->storageRoundTrip((string) $this->option('disk'))),
            $this->check('queue', true, fn () => $this->queueSize()),
            $this->check('scheduler_heartbeat', false, fn () => $heartbeat->isFreshAnywhere('scheduler', self::HEARTBEAT_MAX_AGE) ? null : 'Heartbeat scheduler tidak ada atau terlambat.'),
            $this->check('worker_heartbeat', false, fn () => $heartbeat->isFreshAnywhere('worker', self::HEARTBEAT_MAX_AGE) ? null : 'Heartbeat worker tidak ada atau terlambat.'),
            $this->check('failed_jobs', false, fn () => $this->count('failed_jobs', fn ($q) => $q, 'job gagal menunggu penanganan')),
            $this->check('outbox_dead_letters', false, fn () => $this->count('outbox_messages', fn ($q) => $q->whereNull('processed_at')->whereNotNull('failed_at'), 'pesan outbox dead-letter')),
            $this->check('integration_freshness', false, fn () => $this->integrationFreshness()),
        ];

        $strict = (bool) $this->option('strict');
        $failed = array_filter($results, fn (array $row): bool => $row['status'] === 'FAIL' || ($strict && $row['status'] === 'WARN'));

        if ($this->option('json')) {
            $this->line((string) json_encode(['ready' => $failed === [], 'checks' => $results], JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Check', 'Kelas', 'Status', 'Durasi (ms)', 'Keterangan'], array_map(fn (array $row): array => [$row['check'], $row['critical'] ? 'kritis' : 'opsional', $row['status'], $row['duration_ms'], $row['detail']], $results));
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  Closure(): ?string  $probe  null when healthy, a safe message when not
     * @return array{check: string, critical: bool, status: string, duration_ms: string, detail: string}
     */
    private function check(string $name, bool $critical, Closure $probe): array
    {
        $startedAt = microtime(true);

        try {
            $problem = $probe();
            $status = $problem === null ? 'OK' : ($critical ? 'FAIL' : 'WARN');
            $detail = $problem ?? '';
        } catch (Throwable $exception) {
            report($exception);
            $status = $critical ? 'FAIL' : 'WARN';
            $detail = class_basename($exception);
        }

        return ['check' => $name, 'critical' => $critical, 'status' => $status, 'duration_ms' => number_format((microtime(true) - $startedAt) * 1000, 1), 'detail' => $detail];
    }

    private function databaseConnection(): ?string
    {
        $connection = $this->option('connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function cacheRoundTrip(Repository $cache): null
    {
        $key = 'ops:readiness:'.Str::uuid();

        $cache->put($key, 'ok', 30);
        $value = $cache->get($key);
        $cache->forget($key);

        if ($value !== 'ok') {
            throw new RuntimeException('Cache round-trip returned an unexpected value.');
        }

        return null;
    }

    private function storageRoundTrip(string $disk): null
    {
        $filesystem = Storage::disk($disk);
        $path = 'ops/readiness/'.Str::uuid().'.txt';

        try {
            if (! $filesystem->put($path, 'ok') || $filesystem->get($path) !== 'ok') {
                throw new RuntimeException('Storage round-trip returned an unexpected value.');
            }
        } finally {
            $filesystem->delete($path);
        }

        return null;
    }

    private function queueSize(): null
    {
        // Reaching the queue backend is what matters; the depth is a metric (ops:metrics).
        Queue::connection()->size();

        return null;
    }

    /**
     * @param  Closure(Builder): Builder  $scope
     */
    private function count(string $table, Closure $scope, string $label): ?string
    {
        $count = $scope(DB::table($table))->count();

        return $count === 0 ? null : "{$count} {$label}.";
    }

    /**
     * Enabled connections whose last accepted extract is older than their
     * allowed age. Nothing is enabled until DEC-008.
     */
    private function integrationFreshness(): ?string
    {
        $stale = [];
        $enabled = 0;

        foreach (app(IntegrationQuery::class)->overview() as $row) {
            $connection = $row['connection'];

            if (! $connection->isPipeline() || ! $connection->enabled) {
                continue;
            }

            $enabled++;
            $maxAge = (int) Config::get("integrations.connections.{$connection->key}.max_age_hours", 26);

            if ($row['freshness_hours'] === null || $row['freshness_hours'] > $maxAge) {
                $stale[] = $connection->key;
            }
        }

        if ($enabled === 0) {
            return null;
        }

        return $stale === [] ? null : 'Data sumber terlambat: '.implode(', ', $stale).'.';
    }
}
