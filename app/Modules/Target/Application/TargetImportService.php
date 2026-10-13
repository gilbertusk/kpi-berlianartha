<?php

declare(strict_types=1);

namespace App\Modules\Target\Application;

use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Idempotency\IdempotencyService;
use App\Modules\Shared\Application\Outbox\OutboxEvent;
use App\Modules\Shared\Application\Outbox\OutboxPublisher;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Target\Domain\Exceptions\TargetEntriesRejected;
use App\Modules\Target\Domain\TargetStatus;
use App\Modules\Target\Domain\TargetValueRules;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTargetVersion;
use App\Modules\Target\Infrastructure\Persistence\Models\TargetImportBatch;
use App\Modules\Target\Infrastructure\Persistence\Models\TargetImportRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Staged target import.
 *
 * 1. Stage: every row of the file is validated and stored with its result.
 *    Nothing touches the targets.
 * 2. Commit: allowed only when no row has an error, and applied in one
 *    transaction. If a target changed since staging, the whole commit is
 *    refused. There is no partial commit.
 *
 * A file is identified by its SHA-256: a committed file is not imported a
 * second time, and committing a batch twice returns the first result.
 * Import only creates or updates drafts; final targets are never changed by
 * a file, and drafts still go through submit and approve.
 */
final readonly class TargetImportService
{
    public const MAX_BYTES = 1_048_576;

    public const MAX_ROWS = 5000;

    private const ROW_INSERT_CHUNK = 500;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OutboxPublisher $outbox,
        private OptimisticLock $lock,
        private IdempotencyService $idempotency,
        private TargetService $targets,
        private TargetQuery $query,
    ) {}

    public function stage(Actor $actor, AssessmentPeriod $period, UnitScope $scope, string $fileName, string $contents): TargetImportBatch
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new BusinessRuleViolation('import_too_large', 'Ukuran file melebihi 1 MB.');
        }

        if (! $period->status->acceptsTargetChanges()) {
            throw new BusinessRuleViolation('period_not_accepting_targets', 'Import target hanya tersedia pada periode Open atau In progress.');
        }

        try {
            $parsed = TargetCsv::parse($contents, self::MAX_ROWS);
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRuleViolation('import_invalid_file', $exception->getMessage());
        }

        if ($parsed['rows'] === []) {
            throw new BusinessRuleViolation('import_empty', 'File tidak berisi baris data.');
        }

        $checksum = hash('sha256', $contents);
        $fileName = mb_substr(basename(str_replace('\\', '/', $fileName)), 0, 255);

        return $this->transactions->run(function () use ($actor, $period, $scope, $fileName, $checksum, $parsed): TargetImportBatch {
            $earlier = TargetImportBatch::query()
                ->where('assessment_period_id', $period->id)
                ->where('file_checksum', $checksum)
                ->whereIn('status', [TargetImportBatch::STATUS_STAGED, TargetImportBatch::STATUS_COMMITTED])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($earlier->contains(fn (TargetImportBatch $batch): bool => $batch->status === TargetImportBatch::STATUS_COMMITTED)) {
                throw new BusinessRuleViolation('import_already_committed', 'File yang sama sudah pernah di-commit untuk periode ini.');
            }

            // The same file staged again by the same account replaces its
            // earlier staging, so the result reflects the current targets.
            foreach ($earlier->where('uploaded_by_user_id', $actor->userId) as $stale) {
                $this->lock->update($stale, $stale->lock_version, [
                    'status' => TargetImportBatch::STATUS_DISCARDED,
                    'discarded_by_user_id' => $actor->userId,
                    'discarded_at' => CarbonImmutable::now(),
                ]);
            }

            $rows = $this->validate($period, $scope, $parsed['rows']);
            $counts = array_count_values(array_map(static fn (StagedImportRow $row): string => $row->status, $rows));
            $summary = [
                'rows' => count($rows),
                'valid' => $counts[TargetImportRow::STATUS_VALID] ?? 0,
                'unchanged' => $counts[TargetImportRow::STATUS_UNCHANGED] ?? 0,
                'skipped' => $counts[TargetImportRow::STATUS_SKIPPED] ?? 0,
                'error' => $counts[TargetImportRow::STATUS_ERROR] ?? 0,
                'new_targets' => count(array_filter($rows, static fn (StagedImportRow $row): bool => $row->status === TargetImportRow::STATUS_VALID && $row->expectedState === 'none')),
            ];

            $batch = TargetImportBatch::query()->create([
                'assessment_period_id' => $period->id,
                'status' => TargetImportBatch::STATUS_STAGED,
                'file_name' => $fileName,
                'file_checksum' => $checksum,
                'row_count' => $summary['rows'],
                'valid_count' => $summary['valid'],
                'unchanged_count' => $summary['unchanged'],
                'error_count' => $summary['error'],
                'summary' => $summary,
                'uploaded_by_user_id' => $actor->userId,
            ]);

            $now = CarbonImmutable::now();

            foreach (array_chunk($rows, self::ROW_INSERT_CHUNK) as $chunk) {
                TargetImportRow::query()->insert(array_map(static fn (StagedImportRow $row): array => [
                    'target_import_batch_id' => $batch->id,
                    'row_number' => $row->line,
                    'status' => $row->status,
                    'raw' => json_encode($row->raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'errors' => $row->errors === [] ? null : json_encode($row->errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'period_target_id' => $row->targetId,
                    'target_value' => $row->targetValue,
                    'unit' => $row->unit,
                    'source_note' => $row->sourceNote,
                    'expected_state' => $row->expectedState,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $this->record($actor, $batch, 'target_import.staged', null, ['file_name' => $fileName, 'file_checksum' => $checksum, ...$summary], null);

            return $batch;
        });
    }

    /**
     * Applies every valid row as a draft target, or nothing at all.
     */
    public function commit(Actor $actor, int $batchId, int $expectedVersion, UnitScope $scope, ?string $reason): TargetImportBatch
    {
        $batch = TargetImportBatch::query()->findOrFail($batchId);

        $this->idempotency->execute(
            'target_import.commit',
            $batch->public_id,
            $batch->file_checksum,
            fn (): array => $this->transactions->run(function () use ($actor, $batchId, $expectedVersion, $scope, $reason): array {
                $batch = TargetImportBatch::query()->lockForUpdate()->findOrFail($batchId);

                if ($batch->status !== TargetImportBatch::STATUS_STAGED) {
                    throw new BusinessRuleViolation('import_not_staged', 'Import ini sudah di-commit atau dibatalkan.');
                }

                if ($batch->error_count > 0) {
                    throw new BusinessRuleViolation('import_has_errors', "Import berisi {$batch->error_count} baris error. Tidak ada baris yang di-commit; perbaiki file lalu unggah ulang.");
                }

                // Unchanged rows are checked too: the file was validated as a
                // whole, so a change to any of its targets makes it stale.
                $rows = TargetImportRow::query()
                    ->where('target_import_batch_id', $batch->id)
                    ->whereIn('status', [TargetImportRow::STATUS_VALID, TargetImportRow::STATUS_UNCHANGED])
                    ->orderBy('row_number')
                    ->get();

                if (! $rows->contains('status', TargetImportRow::STATUS_VALID)) {
                    throw new BusinessRuleViolation('import_nothing_to_commit', 'Tidak ada baris yang mengubah target.');
                }

                $targetIds = [];

                foreach ($rows as $row) {
                    if ($row->period_target_id !== null) {
                        $targetIds[] = $row->period_target_id;
                    }
                }

                PeriodTarget::query()->whereIn('id', $targetIds)->orderBy('id')->lockForUpdate()->get();
                $states = $this->states($targetIds);
                $entries = [];

                foreach ($rows as $row) {
                    if ($row->period_target_id === null || $row->target_value === null || ($states[$row->period_target_id] ?? 'none') !== $row->expected_state) {
                        throw new BusinessRuleViolation('import_stale', "Target pada baris {$row->row_number} berubah sejak file divalidasi. Tidak ada baris yang di-commit; unggah ulang file untuk validasi terbaru.");
                    }

                    if ($row->status !== TargetImportRow::STATUS_VALID) {
                        continue;
                    }

                    $entries[] = new TargetEntry((string) $row->row_number, $row->period_target_id, TargetValueRules::display($row->target_value), (string) $row->unit, $row->source_note);
                }

                try {
                    $result = $this->targets->saveDrafts($actor, $batch->assessment_period_id, $scope, $entries, $reason, false, PeriodTargetVersion::SOURCE_IMPORT, $batch->id);
                } catch (TargetEntriesRejected $rejected) {
                    $lines = implode(', ', array_keys($rejected->rowErrors));

                    throw new BusinessRuleViolation('import_stale', "Baris {$lines} tidak lagi valid. Tidak ada baris yang di-commit; unggah ulang file untuk validasi terbaru.");
                }

                $batch = $this->lock->update($batch, $expectedVersion, [
                    'status' => TargetImportBatch::STATUS_COMMITTED,
                    'committed_by_user_id' => $actor->userId,
                    'committed_at' => CarbonImmutable::now(),
                ]);

                $totals = ['file_name' => $batch->file_name, 'file_checksum' => $batch->file_checksum, 'rows' => $batch->row_count, ...$result];
                $this->record($actor, $batch, 'target_import.committed', ['status' => TargetImportBatch::STATUS_STAGED], ['status' => TargetImportBatch::STATUS_COMMITTED, ...$totals], $reason);

                $this->outbox->publish(new OutboxEvent(
                    eventType: 'ImportCommitted',
                    eventVersion: 1,
                    aggregateType: 'target_import_batch',
                    aggregateId: $batch->public_id,
                    payload: ['kind' => 'target', 'period_id' => $batch->period->public_id, ...$totals],
                    deduplicationKey: "target-import-committed:{$batch->public_id}",
                ));

                return $result;
            }),
        );

        return $batch->refresh();
    }

    public function discard(Actor $actor, int $batchId, int $expectedVersion): TargetImportBatch
    {
        return $this->transactions->run(function () use ($actor, $batchId, $expectedVersion): TargetImportBatch {
            $batch = TargetImportBatch::query()->lockForUpdate()->findOrFail($batchId);

            if ($batch->status !== TargetImportBatch::STATUS_STAGED) {
                throw new BusinessRuleViolation('import_not_staged', 'Import ini sudah di-commit atau dibatalkan.');
            }

            $batch = $this->lock->update($batch, $expectedVersion, [
                'status' => TargetImportBatch::STATUS_DISCARDED,
                'discarded_by_user_id' => $actor->userId,
                'discarded_at' => CarbonImmutable::now(),
            ]);

            $this->record($actor, $batch, 'target_import.discarded', ['status' => TargetImportBatch::STATUS_STAGED], ['status' => TargetImportBatch::STATUS_DISCARDED], null);

            return $batch;
        });
    }

    /**
     * Rows of the fill-in file: every target in scope that a file may still
     * set (no final or submitted version), with the current draft value.
     *
     * @return list<list<string>>
     */
    public function templateRows(AssessmentPeriod $period, UnitScope $scope): array
    {
        $targets = $this->query->base($period, $scope)
            ->with('definitionVersion')
            ->orderBy('unit_code')->orderBy('subject_type')->orderBy('subject_code')->orderBy('kpi_code')
            ->get();

        $latest = $this->latestVersions(self::ids($targets));
        $rows = [TargetCsv::COLUMNS];

        foreach ($targets as $target) {
            $version = $latest[$target->id] ?? null;

            if ($version !== null && $version->status !== TargetStatus::Draft) {
                continue;
            }

            $rows[] = [
                TargetCsv::cell($target->kpi_code),
                TargetCsv::cell($target->kpi_name),
                $target->subject_type->value,
                TargetCsv::cell($target->subject_code),
                TargetCsv::cell($target->subject_name),
                (string) $target->definitionVersion->unit,
                $version === null ? '' : TargetValueRules::display($version->target_value),
                TargetCsv::cell($version?->source_note),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{line: int, cells: array<string, string>}>  $records
     * @return list<StagedImportRow>
     */
    private function validate(AssessmentPeriod $period, UnitScope $scope, array $records): array
    {
        $index = [];
        $targets = $this->query->base($period, $scope)->with('definitionVersion')->get();

        foreach ($targets as $target) {
            $index[self::key($target->kpi_code, $target->subject_type->value, $target->subject_code)][] = $target;
        }

        $latest = $this->latestVersions(self::ids($targets));
        $rows = [];
        /** @var array<int, StagedImportRow> $firstRowOf target id => first row that sets it */
        $firstRowOf = [];

        foreach ($records as $record) {
            $cells = $record['cells'];
            $unit = $cells['unit'] ?? '';
            $note = ($cells['source_note'] ?? '') === '' ? null : $cells['source_note'];
            $row = new StagedImportRow($record['line'], $cells, $unit === '' ? null : mb_substr($unit, 0, 30), $note);
            $rows[] = $row;

            $target = $this->resolveTarget($row, $index);

            if ($target === null) {
                continue;
            }

            $row->targetId = $target->id;
            $rawValue = $cells['target_value'] ?? '';

            if ($rawValue === '') {
                $row->status = TargetImportRow::STATUS_SKIPPED;

                continue;
            }

            if (isset($firstRowOf[$target->id])) {
                $first = $firstRowOf[$target->id];
                $row->reject("Duplikat: target yang sama sudah ada pada baris {$first->line}.");
                $first->reject("Duplikat: target yang sama muncul lagi pada baris {$row->line}.");

                continue;
            }

            $firstRowOf[$target->id] = $row;
            $version = $latest[$target->id] ?? null;

            try {
                $row->targetValue = $target->valueRules()->parse($rawValue, $unit);
            } catch (InvalidArgumentException $exception) {
                $row->reject($exception->getMessage());
            }

            if ($version?->status === TargetStatus::Approved) {
                $row->reject('Target sudah final. File import tidak mengubah target final; lakukan revisi per target dengan alasan.');
            } elseif ($version?->status === TargetStatus::Submitted) {
                $row->reject('Target sedang menunggu persetujuan dan tidak dapat diubah lewat file.');
            }

            if ($row->errors === []) {
                $row->expectedState = self::state($version);
                $row->status = $version !== null && $version->target_value === $row->targetValue && $version->source_note === $note
                    ? TargetImportRow::STATUS_UNCHANGED
                    : TargetImportRow::STATUS_VALID;
            }
        }

        return $rows;
    }

    /**
     * Finds the target a row refers to, or records why it cannot be found.
     *
     * @param  array<string, list<PeriodTarget>>  $index
     */
    private function resolveTarget(StagedImportRow $row, array $index): ?PeriodTarget
    {
        $kpiCode = $row->raw['kpi_code'] ?? '';
        $subjectType = mb_strtolower($row->raw['subject_type'] ?? '');
        $subjectCode = $row->raw['subject_code'] ?? '';

        if ($kpiCode === '') {
            $row->reject('kpi_code wajib diisi.');
        }

        if (! in_array($subjectType, ['employee', 'unit'], true)) {
            $row->reject('subject_type harus "employee" atau "unit".');
        }

        if ($subjectCode === '') {
            $row->reject('subject_code wajib diisi.');
        }

        if ($row->errors !== []) {
            return null;
        }

        $matches = $index[self::key($kpiCode, $subjectType, $subjectCode)] ?? [];

        if (count($matches) === 1) {
            return $matches[0];
        }

        $row->reject($matches === []
            ? 'KPI untuk subjek ini tidak ditemukan pada periode ini dalam scope Anda.'
            : 'KPI ini memiliki lebih dari satu versi untuk subjek yang sama; isi target secara manual.');

        return null;
    }

    /**
     * @param  list<int>  $targetIds
     * @return array<int, string>
     */
    private function states(array $targetIds): array
    {
        return array_map(static fn (PeriodTargetVersion $version): string => self::state($version), $this->latestVersions($targetIds));
    }

    /**
     * @param  list<int>  $targetIds
     * @return array<int, PeriodTargetVersion>
     */
    private function latestVersions(array $targetIds): array
    {
        $latest = [];

        foreach (array_chunk($targetIds, 1000) as $chunk) {
            foreach (PeriodTargetVersion::query()->whereIn('period_target_id', $chunk)->orderBy('version_number')->get() as $version) {
                $latest[$version->period_target_id] = $version;
            }
        }

        return $latest;
    }

    /**
     * @param  Collection<int, PeriodTarget>  $targets
     * @return list<int>
     */
    private static function ids(Collection $targets): array
    {
        return array_values(array_map(static fn (PeriodTarget $target): int => $target->id, $targets->all()));
    }

    private static function state(?PeriodTargetVersion $version): string
    {
        return $version === null ? 'none' : "{$version->status->value}:{$version->id}:{$version->lock_version}";
    }

    private static function key(string $kpiCode, string $subjectType, string $subjectCode): string
    {
        return mb_strtoupper(trim($kpiCode)).'|'.$subjectType.'|'.mb_strtoupper(trim($subjectCode));
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Actor $actor, TargetImportBatch $batch, string $action, ?array $before, ?array $after, ?string $reason): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'target_import_batch',
            entityId: $batch->public_id,
            before: $before,
            after: $after,
            reason: $reason,
            context: $actor->audit,
        ));
    }
}
