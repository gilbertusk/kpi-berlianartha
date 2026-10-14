<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\Reporting\Domain\ExportStatus;
use App\Modules\Reporting\Domain\ReportFormat;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateReportExport;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportExport;
use App\Modules\Reporting\Infrastructure\Writers\PdfExportWriter;
use App\Modules\Reporting\Infrastructure\Writers\XlsxExportWriter;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Report exports: request, generation in a queue worker, retry, download
 * link, and purge.
 *
 * An export is generated as the account that asked for it, with that
 * account's access at the moment the worker runs, and the access is checked
 * once more when the file is downloaded. The file lives on a private disk
 * under a server-generated name and leaves only through a short-lived
 * signed link bound to its owner.
 */
final readonly class ExportService
{
    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private ReportCatalog $catalog,
        private FilesystemFactory $filesystems,
        private BusinessTime $time,
        private string $disk,
        private int $linkTtlSeconds,
        private int $retentionHours,
        private int $maxRows,
        private int $pdfMaxRows,
        private int $maxInFlight,
        private int $staleAfterMinutes,
    ) {
        if ($this->disk === 'public' || Config::get("filesystems.disks.{$this->disk}.visibility") === 'public') {
            throw new LogicException('Report exports must be stored on a private disk.');
        }
    }

    public function retentionHours(): int
    {
        return $this->retentionHours;
    }

    /**
     * Queues an export. An identical request that is still queued or
     * running is returned instead of starting a second one.
     *
     * @param  array<mixed>  $filters
     */
    public function request(Actor $actor, User $user, string $reportKey, string $format, array $filters): ReportExport
    {
        $format = ReportFormat::tryFrom($format) ?? throw new BusinessRuleViolation('invalid_format', 'Format ekspor tidak dikenal.');
        $validated = ReportFilters::fromArray($filters);
        [$report] = $this->catalog->authorize($user, $reportKey, $validated);

        $canonical = $validated->toArray();
        $fingerprint = hash('sha256', json_encode([$user->id, $report->key(), $format->value, $canonical], JSON_THROW_ON_ERROR));

        try {
            return $this->transactions->run(function () use ($actor, $user, $report, $format, $canonical, $fingerprint): ReportExport {
                $existing = $this->inFlight($user, $fingerprint);

                if ($existing !== null) {
                    return $existing;
                }

                $this->assertCapacity($user);

                $export = ReportExport::query()->create([
                    'user_id' => $user->id,
                    'report_key' => $report->key(),
                    'format' => $format,
                    'filters' => $canonical,
                    'request_fingerprint' => $fingerprint,
                    'status' => ExportStatus::Queued,
                    'classification' => $report->classification(),
                ]);

                $this->record($actor, $export, 'report_export.requested', ['report' => $report->key(), 'format' => $format->value, 'filters' => $canonical]);
                GenerateReportExport::dispatch($export->id)->afterCommit();

                return $export;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->inFlight($user, $fingerprint) ?? throw new BusinessRuleViolation('export_conflict', 'Ekspor yang sama sedang dibuat. Muat ulang halaman.');
        }
    }

    /**
     * Queues a failed export again. Calling it for an export that is already
     * queued, running, or completed changes nothing.
     */
    public function retry(Actor $actor, User $user, int $exportId): ReportExport
    {
        try {
            return $this->transactions->run(function () use ($actor, $user, $exportId): ReportExport {
                $export = ReportExport::query()->lockForUpdate()->findOrFail($exportId);
                $this->assertOwner($user, $export);

                if ($export->status !== ExportStatus::Failed) {
                    return $export;
                }

                // The request is re-validated before the worker spends time on it.
                $this->catalog->authorize($user, $export->report_key, ReportFilters::fromArray($export->filters));
                $this->assertCapacity($user);

                $export->forceFill(['status' => ExportStatus::Queued, 'failure_code' => null, 'rows_written' => 0])->save();
                $this->record($actor, $export, 'report_export.retried', ['attempts' => $export->attempts]);
                GenerateReportExport::dispatch($export->id)->afterCommit();

                return $export;
            });
        } catch (UniqueConstraintViolationException) {
            // The same request is already in flight as another export.
            $failed = ReportExport::query()->findOrFail($exportId);
            $this->assertOwner($user, $failed);

            return $this->inFlight($user, $failed->request_fingerprint) ?? $failed;
        }
    }

    /**
     * Runs in the queue worker. Safe to call more than once: only a queued
     * export is picked up.
     *
     * @throws Throwable an unexpected failure, so the queue can retry the job
     */
    public function generate(int $exportId): void
    {
        $export = $this->transactions->run(function () use ($exportId): ?ReportExport {
            $export = ReportExport::query()->lockForUpdate()->find($exportId);

            if ($export === null || $export->status !== ExportStatus::Queued) {
                return null;
            }

            $export->forceFill(['status' => ExportStatus::Running, 'attempts' => $export->attempts + 1, 'started_at' => CarbonImmutable::now(), 'rows_written' => 0])->save();

            return $export;
        });

        if ($export === null) {
            return;
        }

        $local = storage_path('app/private/report-exports-tmp').DIRECTORY_SEPARATOR.$export->public_id.'.'.$export->format->value;
        $stored = null;

        try {
            $user = $export->user()->first();

            if ($user === null || ! $user->isActive()) {
                $this->fail($export, 'requester_inactive');

                return;
            }

            try {
                [$report, $context] = $this->catalog->authorize($user, $export->report_key, ReportFilters::fromArray($export->filters));
            } catch (AuthorizationException) {
                $this->fail($export, 'not_authorized');

                return;
            } catch (BusinessRuleViolation) {
                $this->fail($export, 'invalid_request');

                return;
            }

            $now = CarbonImmutable::now();
            File::ensureDirectoryExists(dirname($local));

            $metadata = new ExportMetadata(
                reportTitle: $report->title(),
                reportKey: $report->key(),
                filters: $export->filters,
                period: $context->period === null ? null : "{$context->period->code} — {$context->period->name} ({$context->period->starts_on} s.d. {$context->period->ends_on})",
                timezone: $this->time->timezoneName(),
                generatedAt: $now,
                generatedBy: $user->name,
                scopeSummary: $context->scopeSummary,
                classification: $report->classification(),
                exportId: $export->public_id,
            );

            $writer = $export->format === ReportFormat::Pdf ? new PdfExportWriter : new XlsxExportWriter;
            $rows = $writer->write(
                $local,
                $metadata,
                $report->columns(),
                $report->rows($context),
                static function (int $written) use ($export): void {
                    ReportExport::query()->whereKey($export->id)->where('status', ExportStatus::Running->value)->update(['rows_written' => $written]);
                },
                $export->format === ReportFormat::Pdf ? $this->pdfMaxRows : $this->maxRows,
            );

            // The object name is generated here; nothing from the request is part of it.
            $path = 'report-exports/'.$now->format('Y/m').'/'.$export->public_id.'.'.$export->format->value;
            $stream = fopen($local, 'rb');

            if ($stream === false) {
                throw new RuntimeException('The export file could not be read back.');
            }

            try {
                if (! $this->filesystems->disk($this->disk)->writeStream($path, $stream)) {
                    throw new RuntimeException('The export could not be stored.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $stored = $path;

            $completed = $this->transactions->run(function () use ($export, $report, $context, $path, $local, $rows, $now): bool {
                // Only a run that still owns the export may complete it.
                $claimed = ReportExport::query()->whereKey($export->id)->where('status', ExportStatus::Running->value)->lockForUpdate()->first();

                if ($claimed === null) {
                    return false;
                }

                $claimed->forceFill([
                    'status' => ExportStatus::Completed,
                    'rows_written' => $rows,
                    'scope_summary' => Str::limit($context->scopeSummary, 480, '…'),
                    'disk' => $this->disk,
                    'path' => $path,
                    'file_name' => Str::slug($report->key(), '-').'-'.$now->setTimezone($this->time->timezoneName())->format('Ymd-His').'.'.$claimed->format->value,
                    'byte_size' => (int) filesize($local),
                    'checksum_sha256' => (string) hash_file('sha256', $local),
                    'completed_at' => CarbonImmutable::now(),
                    'expires_at' => CarbonImmutable::now()->addHours($this->retentionHours),
                    'failure_code' => null,
                ])->save();

                $this->record(Actor::system('report-export-worker', $claimed->public_id), $claimed, 'report_export.completed', [
                    'report' => $claimed->report_key, 'format' => $claimed->format->value, 'rows' => $rows, 'bytes' => $claimed->byte_size,
                    'checksum' => $claimed->checksum_sha256, 'scope' => $claimed->scope_summary, 'expires_at' => $claimed->expires_at?->toIso8601String(),
                ]);

                return true;
            });

            if ($completed) {
                $stored = null;
            }
        } catch (TooManyRows) {
            $this->fail($export, $export->format === ReportFormat::Pdf ? 'too_many_rows_for_pdf' : 'too_many_rows');
        } catch (Throwable $exception) {
            // Only the exception class is logged: a message can carry row data.
            Log::error('Report export failed.', ['export' => $export->public_id, 'exception' => $exception::class]);
            ReportExport::query()->whereKey($export->id)->where('status', ExportStatus::Running->value)->update(['status' => ExportStatus::Queued->value]);

            throw $exception;
        } finally {
            File::delete($local);

            // A file that was stored but never became the export's file is removed.
            if ($stored !== null) {
                rescue(fn () => $this->filesystems->disk($this->disk)->delete($stored), report: false);
            }
        }
    }

    /**
     * Called when the queue gives up on the job.
     */
    public function giveUp(int $exportId): void
    {
        $export = ReportExport::query()->find($exportId);

        if ($export !== null && $export->status->isInFlight()) {
            $this->fail($export, 'generation_failed');
        }
    }

    /**
     * A short-lived signed link bound to the owner of the export.
     */
    public function downloadLink(User $user, ReportExport $export): string
    {
        $this->assertOwner($user, $export);
        $this->assertDownloadable($export);

        return URL::temporarySignedRoute('reports.exports.download', CarbonImmutable::now()->addSeconds($this->linkTtlSeconds), [
            'export' => $export->public_id,
            'viewer' => $user->public_id,
        ]);
    }

    /**
     * Opens the file for its owner after checking once more that the account
     * may still see this report with these filters, and records the download.
     *
     * @return resource
     */
    public function open(Actor $actor, User $user, ReportExport $export)
    {
        $this->assertOwner($user, $export);
        $this->assertDownloadable($export);
        $this->catalog->authorize($user, $export->report_key, ReportFilters::fromArray($export->filters));

        $stream = $this->filesystems->disk((string) $export->disk)->readStream((string) $export->path);

        if (! is_resource($stream)) {
            throw new BusinessRuleViolation('export_unavailable', 'File ekspor tidak ditemukan di penyimpanan.');
        }

        $this->transactions->run(function () use ($actor, $export): void {
            ReportExport::query()->whereKey($export->id)->increment('download_count');
            $this->record($actor, $export, 'report_export.downloaded', ['report' => $export->report_key, 'format' => $export->format->value, 'checksum' => $export->checksum_sha256]);
        });

        return $stream;
    }

    /**
     * Deletes the files of exports past their retention and marks them
     * expired. An export whose file could not be deleted stays as it is and
     * is tried again on the next run. Returns how many were purged.
     */
    public function purgeExpired(CarbonImmutable $now, int $limit = 200): int
    {
        $purged = 0;

        $exports = ReportExport::query()
            ->where('status', ExportStatus::Completed->value)
            ->whereNull('purged_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($exports as $export) {
            try {
                if ($export->disk !== null && $export->path !== null) {
                    $disk = $this->filesystems->disk($export->disk);

                    if ($disk->exists($export->path) && ! $disk->delete($export->path)) {
                        continue;
                    }
                }

                $this->transactions->run(function () use ($export, $now): void {
                    $export->forceFill(['status' => ExportStatus::Expired, 'purged_at' => $now])->save();
                    $this->record(Actor::system('report-export-purge', $export->public_id), $export, 'report_export.purged', ['report' => $export->report_key]);
                });
                $purged++;
            } catch (Throwable $exception) {
                Log::warning('Report export purge failed.', ['export' => $export->public_id, 'exception' => $exception::class]);
            }
        }

        return $purged;
    }

    /**
     * Ends exports that have been "running" longer than any worker could
     * still be working on them (a killed worker never reports back), and
     * removes temporary files such a worker left behind. Returns how many
     * exports were failed.
     */
    public function failStale(CarbonImmutable $now): int
    {
        $threshold = $now->subMinutes($this->staleAfterMinutes);
        $failed = 0;

        foreach (ReportExport::query()->where('status', ExportStatus::Running->value)->where('started_at', '<', $threshold)->orderBy('id')->limit(200)->get() as $export) {
            $changed = ReportExport::query()->whereKey($export->id)->where('status', ExportStatus::Running->value)->where('started_at', '<', $threshold)->exists();

            if ($changed) {
                $this->fail($export, 'generation_failed');
                $failed++;
            }
        }

        $directory = storage_path('app/private/report-exports-tmp');

        if (File::isDirectory($directory)) {
            foreach (File::files($directory) as $file) {
                if ($file->getMTime() < $threshold->getTimestamp()) {
                    File::delete($file->getPathname());
                }
            }
        }

        return $failed;
    }

    private function assertCapacity(User $user): void
    {
        $busy = ReportExport::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [ExportStatus::Queued->value, ExportStatus::Running->value])
            ->count();

        if ($busy >= $this->maxInFlight) {
            throw new BusinessRuleViolation('too_many_exports', 'Masih ada ekspor Anda yang sedang diproses. Tunggu sampai selesai sebelum meminta yang baru.');
        }
    }

    private function inFlight(User $user, string $fingerprint): ?ReportExport
    {
        return ReportExport::query()
            ->where('user_id', $user->id)
            ->where('request_fingerprint', $fingerprint)
            ->whereIn('status', [ExportStatus::Queued->value, ExportStatus::Running->value])
            ->first();
    }

    private function fail(ReportExport $export, string $code): void
    {
        $this->transactions->run(function () use ($export, $code): void {
            $export->forceFill(['status' => ExportStatus::Failed, 'failure_code' => $code])->save();
            $this->record(Actor::system('report-export-worker', $export->public_id), $export, 'report_export.failed', ['report' => $export->report_key, 'failure' => $code]);
        });
    }

    private function assertOwner(User $user, ReportExport $export): void
    {
        if ($export->user_id !== $user->id) {
            throw new AuthorizationException;
        }
    }

    private function assertDownloadable(ReportExport $export): void
    {
        if (! $export->isDownloadableAt(CarbonImmutable::now())) {
            throw new BusinessRuleViolation('export_expired', 'Ekspor ini belum selesai atau sudah kedaluwarsa. Buat ekspor baru.');
        }
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function record(Actor $actor, ReportExport $export, string $action, array $after): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'report_export',
            entityId: $export->public_id,
            before: null,
            after: $after,
            reason: null,
            context: $actor->audit,
        ));
    }
}
