<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Internal (CLI-only) readiness check for operators and container tooling.
 *
 * Unlike the public GET /health liveness probe, this verifies dependencies.
 * Command output names the failing check and exception type only. The full
 * exception is reported to the application log (stderr in the production
 * image), which may include host or user names but never configured secrets.
 */
#[Signature('ops:readiness {--disk=s3 : Filesystem disk used for the storage round-trip} {--connection= : Database connection (default connection when omitted)}')]
#[Description('Verify database, cache, and object storage connectivity')]
final class ReadinessCommand extends Command
{
    public function handle(Repository $cache): int
    {
        $results = [
            $this->check('database', fn () => DB::connection($this->databaseConnection())->select('select 1 as ok')),
            $this->check('cache', fn () => $this->cacheRoundTrip($cache)),
            $this->check('storage', fn () => $this->storageRoundTrip((string) $this->option('disk'))),
        ];

        $this->table(['Check', 'Status', 'Durasi (ms)', 'Keterangan'], $results);

        $failed = array_filter($results, fn (array $row): bool => $row[1] !== 'OK');

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  Closure(): mixed  $probe
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function check(string $name, Closure $probe): array
    {
        $startedAt = microtime(true);

        try {
            $probe();
            $status = 'OK';
            $detail = '';
        } catch (Throwable $exception) {
            report($exception);
            $status = 'FAIL';
            $detail = class_basename($exception);
        }

        return [$name, $status, number_format((microtime(true) - $startedAt) * 1000, 1), $detail];
    }

    private function databaseConnection(): ?string
    {
        $connection = $this->option('connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function cacheRoundTrip(Repository $cache): void
    {
        $key = 'ops:readiness:'.Str::uuid();

        $cache->put($key, 'ok', 30);
        $value = $cache->get($key);
        $cache->forget($key);

        if ($value !== 'ok') {
            throw new RuntimeException('Cache round-trip returned an unexpected value.');
        }
    }

    private function storageRoundTrip(string $disk): void
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
    }
}
