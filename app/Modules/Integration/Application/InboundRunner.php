<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

use App\Models\User;
use App\Modules\Accounting\Infrastructure\Persistence\Models\ArSnapshot;
use App\Modules\IdentityAccess\Domain\AccountStatus;
use App\Modules\Integration\Domain\Manifest;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationRun;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Inbound pipeline of one connection: receive → authenticate and checksum →
 * schema and semantic validation (by the owning module's staged import) →
 * reconcile with the manifest → atomic commit when the connection is
 * approved for it → archive or quarantine. Each file is its own run: a
 * failing file or system never touches another one, and a domain
 * transaction is never left half done.
 */
final readonly class InboundRunner
{
    private const LOCK_SECONDS = 3600;

    private const MAX_MANIFEST_BYTES = 16384;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private ConnectionRegistry $connections,
        private ExceptionRegister $exceptions,
        private InboundAdapters $adapters,
        private BusinessTime $time,
    ) {}

    /**
     * Processes the waiting files of every runnable connection, or of one.
     * One process per connection at a time; a failure on one file never
     * stops the others.
     *
     * @return array{runs: list<IntegrationRun>, skipped: array<string, string>}
     */
    public function run(?string $only = null, ?Actor $triggeredBy = null): array
    {
        $runs = [];
        $skipped = [];

        foreach ($this->connections->all() as $key => $connection) {
            if (! $connection->isPipeline() || ($only !== null && $only !== $key)) {
                continue;
            }

            $reason = $connection->blockedBecause() ?? ($this->runAs($connection) === null ? 'Akun layanan (run-as) tidak ditemukan atau tidak aktif.' : null);

            if ($reason !== null) {
                $skipped[$key] = $reason;

                continue;
            }

            $lock = Cache::lock("integration-run:{$key}", self::LOCK_SECONDS);

            if (! $lock->get()) {
                $skipped[$key] = 'Koneksi sedang diproses oleh proses lain.';

                continue;
            }

            try {
                if ($triggeredBy !== null) {
                    $this->triggered($triggeredBy, 'integration.run_triggered', $key, null);
                }

                foreach ($this->waiting($connection) as $file) {
                    try {
                        $runs[] = $this->process($connection, $file, $this->runFor($connection, $file));
                    } catch (Throwable $failure) {
                        // Even recording the failure failed (e.g. the database is down): leave the file for the next run.
                        $this->log($connection, null, $failure);
                    }
                }
            } finally {
                $lock->release();
            }
        }

        return ['runs' => $runs, 'skipped' => $skipped];
    }

    /**
     * Manual recovery of a failed or dead-lettered run whose file is still
     * waiting: one more attempt, whatever the attempt count.
     */
    public function retry(IntegrationRun $run, ?Actor $triggeredBy = null): IntegrationRun
    {
        if ($run->mode !== IntegrationRun::INBOUND || ! in_array($run->status, [IntegrationRun::FAILED, IntegrationRun::DEAD_LETTER], true)) {
            throw new BusinessRuleViolation('not_retryable', 'Hanya run inbound yang gagal atau dead-letter yang dapat diulang.');
        }

        $connection = $this->connections->get($run->connection);
        $reason = $connection->blockedBecause();

        if ($reason !== null) {
            throw new BusinessRuleViolation('connection_blocked', $reason);
        }

        if (! $this->disk()->exists($this->inbox($connection).'/'.$run->file_name)) {
            throw new BusinessRuleViolation('file_missing', 'File run ini tidak lagi ada di inbox.');
        }

        $lock = Cache::lock("integration-run:{$connection->key}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new BusinessRuleViolation('connection_busy', 'Koneksi sedang diproses oleh proses lain. Coba lagi nanti.');
        }

        try {
            if ($triggeredBy !== null) {
                $this->triggered($triggeredBy, 'integration.retry_triggered', $connection->key, $run->public_id);
            }

            return $this->process($connection, $run->file_name, $run, manual: true);
        } finally {
            $lock->release();
        }
    }

    private function process(Connection $connection, string $file, IntegrationRun $run, bool $manual = false): IntegrationRun
    {
        if (! $manual && $run->status === IntegrationRun::RECEIVED && $run->attempts >= $this->maxAttempts()) {
            // A run left behind by a crash counts as a failed attempt.
            return $this->failed($connection, $run, new RuntimeException('previous attempt did not finish'), true);
        }

        $local = TempFile::create('kpi-integration-');
        $mapped = TempFile::create('kpi-integration-mapped-');

        try {
            $run->forceFill(['status' => IntegrationRun::RECEIVED, 'attempts' => $run->attempts + 1, 'started_at' => CarbonImmutable::now(), 'error_code' => null, 'error_message' => null])->save();
            $manifestJson = $this->receive($connection, $file, $local, $run);
            $manifest = $manifestJson === null ? null : $this->authenticate($connection, $file, $manifestJson, $local, $run);

            if ($manifest === null || ! $this->current($connection, $manifest, $run)) {
                return $this->quarantine($connection, $file, $run);
            }

            $previous = $this->replayOf($connection, $manifest, $run);

            if ($previous !== null) {
                // A replayed file: the data is already in; nothing is staged again.
                $run->forceFill(['status' => IntegrationRun::DUPLICATE, 'row_count' => $manifest->rows, 'accepted_count' => 0, 'rejected_count' => 0, 'duplicate_count' => $manifest->rows, 'target_type' => $previous->target_type, 'target_public_id' => $previous->target_public_id])->save();

                return $this->archive($connection, $file, $run);
            }

            CsvHeaderMapper::map($local, $mapped, $connection->columnMap);
            $runAs = $this->runAs($connection) ?? throw new AuthorizationException;
            $actor = new Actor($runAs->id, Actor::system('integration', "{$connection->key}:{$run->public_id}")->audit);
            $target = $this->adapter($connection)->stage($connection, $manifest, $mapped, $runAs, $actor);

            if (! $target->created) {
                // The module already holds this content (staged or loaded by someone else): never commit it on their behalf.
                $run->forceFill(['status' => IntegrationRun::DUPLICATE, 'row_count' => $target->rowCount, 'accepted_count' => 0, 'rejected_count' => 0, 'duplicate_count' => $target->rowCount, 'target_type' => $target->type, 'target_public_id' => $target->publicId])->save();

                return $this->archive($connection, $file, $run);
            }

            $reconciliation = $this->reconcile($connection, $manifest, $target);
            $run->forceFill([
                'status' => IntegrationRun::STAGED,
                'row_count' => $target->rowCount,
                'accepted_count' => $target->rejectedCount === 0 ? $target->rowCount : 0,
                'rejected_count' => $target->rejectedCount,
                'duplicate_count' => 0,
                'reconciliation' => $reconciliation['detail'],
                'reconciled' => $reconciliation['reconciled'],
                'target_type' => $target->type,
                'target_public_id' => $target->publicId,
            ])->save();

            if ($target->rejectedCount > 0) {
                $this->exceptions->record($run, $connection->key, 'rows_rejected', "{$target->rejectedCount} baris ditolak oleh validasi; tidak ada baris yang disimpan. Lihat batch {$target->publicId}.");
            } elseif (! $reconciliation['reconciled']) {
                $this->exceptions->record($run, $connection->key, 'reconciliation_difference', $connection->restricted
                    ? "Manifest dan batch {$target->publicId} berbeda; nilai terbatas, lihat di modul tujuan."
                    : 'Manifest dan batch berbeda: '.json_encode($reconciliation['detail'], JSON_UNESCAPED_UNICODE));
            }

            if ($connection->autoCommit && $reconciliation['reconciled'] && $target->status === 'staged') {
                $this->commit($connection, $run, $target, $runAs, $actor);
            }

            return $this->archive($connection, $file, $run);
        } catch (BusinessRuleViolation $violation) {
            // The target refused the file (schema, calendar, scope rules): it never retries by itself.
            return $this->safely($connection, $run, $manual, fn (): IntegrationRun => $this->refuseAndQuarantine($connection, $file, $run, 'rejected_by_target', $violation->rule, $violation->safeMessage()));
        } catch (AuthorizationException) {
            return $this->safely($connection, $run, $manual, fn (): IntegrationRun => $this->refuseAndQuarantine($connection, $file, $run, 'not_authorized', 'not_authorized', 'Akun layanan tidak berwenang memuat data team pada manifest.'));
        } catch (InvalidArgumentException $invalid) {
            // A malformed file (empty, header mapping collision) is not a passing failure.
            return $this->safely($connection, $run, $manual, fn (): IntegrationRun => $this->refuseAndQuarantine($connection, $file, $run, 'invalid_file', 'invalid_file', $invalid->getMessage()));
        } catch (Throwable $failure) {
            return $this->failed($connection, $run, $failure, $manual);
        } finally {
            @unlink($local);
            @unlink($mapped);
        }
    }

    /**
     * Size limits before anything is read, then a streamed copy of the
     * data file. Returns the manifest text, or null when refused.
     */
    private function receive(Connection $connection, string $file, string $local, IntegrationRun $run): ?string
    {
        $disk = $this->disk();
        $inbox = $this->inbox($connection);
        $maxBytes = max(1, (int) Config::get('integrations.max_file_bytes', 20 * 1024 * 1024));

        if ($disk->size("{$inbox}/{$file}") > $maxBytes) {
            return $this->refuse($connection, $run, 'file_too_large', "File melebihi batas {$maxBytes} byte.");
        }

        if ($disk->size("{$inbox}/{$file}.manifest.json") > self::MAX_MANIFEST_BYTES) {
            return $this->refuse($connection, $run, 'manifest_invalid', 'Manifest melebihi '.self::MAX_MANIFEST_BYTES.' byte.');
        }

        $in = $disk->readStream("{$inbox}/{$file}");
        $out = fopen($local, 'wb');

        if (! is_resource($in) || $out === false) {
            throw new RuntimeException('Cannot read the inbound file.');
        }

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }

        return (string) $disk->get("{$inbox}/{$file}.manifest.json");
    }

    /**
     * An extract older than one already accepted for the same team, date,
     * and source would replace newer data: it is refused, not versioned.
     */
    private function current(Connection $connection, Manifest $manifest, IntegrationRun $run): bool
    {
        $newer = IntegrationRun::query()
            ->where('connection', $connection->key)
            ->where('id', '!=', $run->id)
            ->whereIn('status', [IntegrationRun::STAGED, IntegrationRun::COMMITTED])
            ->where('manifest->unit', $manifest->unit)
            ->where('manifest->coverage_date', $manifest->coverageDate)
            ->where('manifest->source_system', $manifest->sourceSystem)
            ->where('watermark', '>', $run->watermark)
            ->exists();

        if ($newer) {
            $this->refuse($connection, $run, 'stale_extract', 'Ekstrak lebih lama dari data yang sudah diterima untuk team, tanggal, dan sumber yang sama.');

            return false;
        }

        return true;
    }

    /**
     * The earlier run of the same content for the same team, date, and
     * source whose target is still live. A rejected target, or another date,
     * is no replay.
     */
    private function replayOf(Connection $connection, Manifest $manifest, IntegrationRun $run): ?IntegrationRun
    {
        $candidates = IntegrationRun::query()
            ->where('connection', $connection->key)
            ->where('content_hash', $run->content_hash)
            ->where('id', '!=', $run->id)
            ->whereIn('status', [IntegrationRun::STAGED, IntegrationRun::COMMITTED])
            ->where('manifest->unit', $manifest->unit)
            ->where('manifest->coverage_date', $manifest->coverageDate)
            ->where('manifest->source_system', $manifest->sourceSystem)
            ->orderByDesc('id')
            ->get();

        return $candidates->first(fn (IntegrationRun $previous): bool => $this->targetLive($previous));
    }

    private function targetLive(IntegrationRun $run): bool
    {
        $status = match ($run->target_type) {
            'production_batch' => ProductionBatch::query()->where('public_id', $run->target_public_id)->value('status'),
            'ar_snapshot' => ArSnapshot::query()->where('public_id', $run->target_public_id)->value('status'),
            default => null,
        };

        return $status !== null && $status !== 'rejected';
    }

    /**
     * Runs a refusal; if even that fails (storage, database), the run is a
     * technical failure instead of escaping the pipeline.
     *
     * @param  callable(): IntegrationRun  $refusal
     */
    private function safely(Connection $connection, IntegrationRun $run, bool $manual, callable $refusal): IntegrationRun
    {
        try {
            return $refusal();
        } catch (Throwable $failure) {
            return $this->failed($connection, $run, $failure, $manual);
        }
    }

    private function refuseAndQuarantine(Connection $connection, string $file, IntegrationRun $run, string $kind, string $code, string $message): IntegrationRun
    {
        $run->forceFill(['error_code' => mb_substr($code, 0, 60), 'error_message' => mb_substr($message, 0, 500)])->save();
        $this->exceptions->record($run, $connection->key, $kind, $kind === $code ? $message : "{$code}: {$message}");

        return $this->quarantine($connection, $file, $run);
    }

    /**
     * Audit of a run or retry a person started from the dashboard.
     */
    private function triggered(Actor $actor, string $action, string $connection, ?string $runId): void
    {
        $this->transactions->run(fn () => $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'integration_connection',
            entityId: $connection,
            before: null,
            after: ['run' => $runId],
            reason: null,
            context: $actor->audit,
        )));
    }

    /**
     * Diagnostics for operations: the class, file, and line, never the
     * message, which may carry connection details.
     */
    private function log(Connection $connection, ?IntegrationRun $run, Throwable $failure): void
    {
        Log::warning('integration.run_failed', [
            'connection' => $connection->key,
            'run' => $run?->public_id,
            'exception' => $failure::class,
            'file' => basename($failure->getFile()),
            'line' => $failure->getLine(),
        ]);
    }

    private function maxAttempts(): int
    {
        return max(1, (int) Config::get('integrations.max_attempts', 3));
    }

    /**
     * Signature, manifest contract, file name, connection, and checksum.
     */
    private function authenticate(Connection $connection, string $file, string $manifestJson, string $local, IntegrationRun $run): ?Manifest
    {
        $hash = (string) hash_file('sha256', $local);
        $run->forceFill(['content_hash' => $hash])->save();

        try {
            $data = json_decode($manifestJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->refuse($connection, $run, 'manifest_invalid', 'Manifest bukan JSON yang valid.');
        }

        if (! is_array($data) || ! Manifest::verify($data, (string) $connection->hmacKey)) {
            return $this->refuse($connection, $run, 'signature_invalid', 'Tanda tangan manifest tidak valid untuk koneksi ini.');
        }

        try {
            $manifest = Manifest::fromArray($data);
        } catch (InvalidArgumentException $invalid) {
            return $this->refuse($connection, $run, 'manifest_invalid', $invalid->getMessage());
        }

        $run->forceFill(['manifest' => $manifest->toRecord(), 'watermark' => $this->extractedAt($manifest)])->save();

        if ($manifest->connection !== $connection->key || $manifest->file !== $file) {
            return $this->refuse($connection, $run, 'manifest_invalid', 'Manifest menyebut koneksi atau nama file lain.');
        }

        if (! hash_equals($manifest->sha256, $hash)) {
            return $this->refuse($connection, $run, 'checksum_mismatch', 'Checksum SHA-256 file tidak sama dengan manifest.');
        }

        return $manifest;
    }

    /**
     * Rows and control totals of the manifest against the staged target.
     *
     * @return array{detail: array<string, mixed>, reconciled: bool}
     */
    private function reconcile(Connection $connection, Manifest $manifest, StagedTarget $target): array
    {
        $detail = ['rows' => ['manifest' => $manifest->rows, 'batch' => $target->rowCount, 'difference' => $target->rowCount - $manifest->rows]];
        $reconciled = $target->rejectedCount === 0 && $target->rowCount === $manifest->rows;

        // Every control total the connection declares must come with the file.
        foreach ($connection->controlTotals as $key) {
            if (! array_key_exists($key, $manifest->controlTotals)) {
                $detail[$key] = ['manifest' => null, 'batch' => $target->totals[$key] ?? null, 'difference' => 'tidak ada di manifest'];
                $reconciled = false;

                continue;
            }

            $actual = $target->totals[$key] ?? null;
            $expected = $manifest->controlTotals[$key];

            if ($actual === null) {
                $detail[$key] = ['manifest' => $expected, 'batch' => null, 'difference' => 'tidak dapat dibandingkan (baris ditolak atau beberapa satuan)'];
                $reconciled = false;

                continue;
            }

            $difference = BigDecimal::of($actual)->minus($expected);
            $detail[$key] = ['manifest' => $expected, 'batch' => $actual, 'difference' => (string) $difference];
            $reconciled = $reconciled && $difference->isZero();
        }

        return ['detail' => $detail, 'reconciled' => $reconciled];
    }

    private function commit(Connection $connection, IntegrationRun $run, StagedTarget $target, User $runAs, Actor $actor): void
    {
        try {
            $this->adapter($connection)->commit($target, $runAs, $actor);
            $run->forceFill(['status' => IntegrationRun::COMMITTED])->save();
        } catch (BusinessRuleViolation $violation) {
            $this->exceptions->record($run, $connection->key, 'commit_refused', "{$violation->rule}: {$violation->safeMessage()}");
        }
    }

    private function failed(Connection $connection, IntegrationRun $run, Throwable $failure, bool $manual): IntegrationRun
    {
        $this->log($connection, $run, $failure);
        $dead = $manual || $run->attempts >= $this->maxAttempts();
        $run->forceFill([
            'status' => $dead ? IntegrationRun::DEAD_LETTER : IntegrationRun::FAILED,
            'error_code' => 'transient_failure',
            // The exception class only: messages may carry connection details.
            'error_message' => mb_substr('Kegagalan teknis ('.class_basename($failure).'); file tetap di inbox.', 0, 500),
            'next_attempt_at' => $dead ? null : CarbonImmutable::now()->addSeconds(min(3600, 60 * (2 ** $run->attempts))),
            'finished_at' => CarbonImmutable::now(),
        ])->save();

        if ($dead) {
            $this->exceptions->record($run, $connection->key, 'dead_letter', "Gagal setelah {$run->attempts} percobaan; menunggu pemulihan manual (integration:retry {$run->public_id}).");
        }

        return $run;
    }

    private function refuse(Connection $connection, IntegrationRun $run, string $code, string $message): null
    {
        $run->forceFill(['error_code' => $code, 'error_message' => mb_substr($message, 0, 500)])->save();
        $this->exceptions->record($run, $connection->key, $code, $message);

        return null;
    }

    private function quarantine(Connection $connection, string $file, IntegrationRun $run): IntegrationRun
    {
        $this->move($connection, $file, "quarantine/{$connection->key}/{$run->public_id}");
        $run->forceFill(['status' => IntegrationRun::QUARANTINED, 'finished_at' => CarbonImmutable::now()])->save();

        return $run;
    }

    private function archive(Connection $connection, string $file, IntegrationRun $run): IntegrationRun
    {
        $this->move($connection, $file, "archive/{$connection->key}/".$this->time->businessDate(CarbonImmutable::now())."/{$run->public_id}");
        $run->forceFill(['finished_at' => CarbonImmutable::now(), 'next_attempt_at' => null])->save();

        return $run;
    }

    private function move(Connection $connection, string $file, string $folder): void
    {
        $disk = $this->disk();
        $root = $this->root();

        foreach ([$file, "{$file}.manifest.json"] as $name) {
            $from = $this->inbox($connection)."/{$name}";

            if ($disk->exists($from)) {
                $disk->move($from, "{$root}/{$folder}/{$name}");
            }
        }
    }

    /**
     * Data files in the inbox that have their manifest and are not waiting
     * for a later retry.
     *
     * @return list<string>
     */
    private function waiting(Connection $connection): array
    {
        $files = [];
        $inbox = $this->inbox($connection);

        foreach ($this->disk()->files($inbox) as $path) {
            $name = basename($path);

            if (str_ends_with($name, '.manifest.json') || ! $this->disk()->exists("{$inbox}/{$name}.manifest.json")) {
                continue;
            }

            $latest = IntegrationRun::query()->where('connection', $connection->key)->where('file_name', $name)->orderByDesc('id')->first();

            if ($latest !== null && ($latest->status === IntegrationRun::DEAD_LETTER || ($latest->status === IntegrationRun::FAILED && $latest->next_attempt_at !== null && $latest->next_attempt_at->isFuture()))) {
                continue;
            }

            $files[] = $name;
        }

        sort($files);

        return $files;
    }

    private function runFor(Connection $connection, string $file): IntegrationRun
    {
        // A failed run, or one a crash left in `received`, continues with its attempt count.
        $unfinished = IntegrationRun::query()->where('connection', $connection->key)->where('file_name', $file)->whereIn('status', [IntegrationRun::FAILED, IntegrationRun::RECEIVED])->orderByDesc('id')->first();

        return $unfinished ?? IntegrationRun::query()->create([
            'connection' => $connection->key,
            'mode' => IntegrationRun::INBOUND,
            'file_name' => mb_substr($file, 0, 160),
            'status' => IntegrationRun::RECEIVED,
        ]);
    }

    private function adapter(Connection $connection): InboundAdapter
    {
        return $this->adapters->for((string) $connection->adapter);
    }

    private function runAs(Connection $connection): ?User
    {
        $user = $connection->runAs === null ? null : User::query()->where('email', $connection->runAs)->first();

        return $user !== null && $user->status === AccountStatus::Active ? $user : null;
    }

    private function extractedAt(Manifest $manifest): ?CarbonImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $manifest->sourceExtractedAt, new DateTimeZone($this->time->timezoneName()));

        return $parsed === false ? null : CarbonImmutable::instance($parsed)->utc();
    }

    private function inbox(Connection $connection): string
    {
        return $this->root()."/inbox/{$connection->key}";
    }

    private function root(): string
    {
        return trim((string) Config::get('integrations.root', 'integration'), '/');
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) Config::get('integrations.disk', 'local'));
    }
}
