<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Application;

use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Domain\EntryKind;
use App\Modules\ActualEvidence\Domain\Exceptions\ActualEntriesRejected;
use App\Modules\ActualEvidence\Domain\ObservationWindow;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\ActualImportBatch;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\ActualImportRow;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
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
use App\Modules\Target\Domain\TargetValueRules;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Staged actual import: upload → parse → validate → preview → atomic commit
 * → reconciliation totals.
 *
 * - Staging stores every row with its validation result and touches no
 *   actual.
 * - A batch commits only when no row has an error, in one transaction, and
 *   is refused entirely when an actual changed since staging.
 * - A file is identified by its SHA-256; a committed file is not imported
 *   twice, and committing a batch twice returns the first result.
 * - A source record (source_system + source_reference) feeds one actual of
 *   a target; a second row or file with the same record is a duplicate.
 * - Import creates or updates drafts only. Submitting, evidence, and
 *   verification follow the normal flow.
 */
final readonly class ActualImportService
{
    public const MAX_BYTES = 1_048_576;

    public const MAX_ROWS = 5000;

    public const NOT_APPLICABLE = 'N/A';

    private const ROW_INSERT_CHUNK = 500;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OutboxPublisher $outbox,
        private OptimisticLock $lock,
        private IdempotencyService $idempotency,
        private ActualService $actuals,
        private ActualQuery $query,
    ) {}

    /**
     * @param  string  $path  local path of the uploaded file
     */
    public function stage(Actor $actor, AssessmentPeriod $period, ActualAccess $access, string $fileName, string $path): ActualImportBatch
    {
        if ($period->status !== PeriodStatus::InProgress) {
            throw new BusinessRuleViolation('period_not_accepting_actuals', 'Import aktual hanya tersedia pada periode In progress.');
        }

        $size = filesize($path);

        if ($size === false || $size > self::MAX_BYTES) {
            throw new BusinessRuleViolation('import_too_large', 'Ukuran file melebihi 1 MB.');
        }

        $fileName = mb_substr(basename(str_replace('\\', '/', $fileName)), 0, 255);
        $format = mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (! in_array($format, ImportSheet::FORMATS, true)) {
            throw new BusinessRuleViolation('import_invalid_file', 'Format file harus CSV atau XLSX.');
        }

        try {
            $records = ImportSheet::read($path, $format, self::MAX_ROWS);
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRuleViolation('import_invalid_file', $exception->getMessage());
        }

        if ($records === []) {
            throw new BusinessRuleViolation('import_empty', 'File tidak berisi baris data.');
        }

        $checksum = (string) hash_file('sha256', $path);

        return $this->transactions->run(function () use ($actor, $period, $access, $fileName, $format, $checksum, $records): ActualImportBatch {
            $earlier = ActualImportBatch::query()
                ->where('assessment_period_id', $period->id)
                ->where('file_checksum', $checksum)
                ->whereIn('status', [ActualImportBatch::STATUS_STAGED, ActualImportBatch::STATUS_COMMITTED])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($earlier->contains('status', ActualImportBatch::STATUS_COMMITTED)) {
                throw new BusinessRuleViolation('import_already_committed', 'File yang sama sudah pernah di-commit untuk periode ini.');
            }

            foreach ($earlier->where('uploaded_by_user_id', $actor->userId) as $stale) {
                $this->lock->update($stale, $stale->lock_version, [
                    'status' => ActualImportBatch::STATUS_DISCARDED,
                    'discarded_by_user_id' => $actor->userId,
                    'discarded_at' => CarbonImmutable::now(),
                ]);
            }

            $rows = $this->validate($period, $access, $records);
            $counts = array_count_values(array_map(static fn (StagedActualRow $row): string => $row->status, $rows));
            $summary = [
                'rows' => count($rows),
                'valid' => $counts[ActualImportRow::STATUS_VALID] ?? 0,
                'unchanged' => $counts[ActualImportRow::STATUS_UNCHANGED] ?? 0,
                'skipped' => $counts[ActualImportRow::STATUS_SKIPPED] ?? 0,
                'error' => $counts[ActualImportRow::STATUS_ERROR] ?? 0,
                'not_applicable' => count(array_filter($rows, static fn (StagedActualRow $row): bool => $row->status === ActualImportRow::STATUS_VALID && $row->kind === EntryKind::NotApplicable)),
            ];

            $batch = ActualImportBatch::query()->create([
                'assessment_period_id' => $period->id,
                'status' => ActualImportBatch::STATUS_STAGED,
                'file_name' => $fileName,
                'file_format' => $format,
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
                ActualImportRow::query()->insert(array_map(static fn (StagedActualRow $row): array => [
                    'actual_import_batch_id' => $batch->id,
                    'row_number' => $row->line,
                    'status' => $row->status,
                    'raw' => json_encode($row->raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'errors' => $row->errors === [] ? null : json_encode($row->errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'period_target_id' => $row->targetId,
                    'window_start' => $row->window?->start,
                    'window_end' => $row->window?->end,
                    'entry_kind' => $row->kind?->value,
                    'actual_value' => $row->value,
                    'unit' => $row->unit,
                    'source_system' => $row->sourceSystem,
                    'source_reference' => $row->sourceReference,
                    'note' => $row->note,
                    'expected_state' => $row->expectedState,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $this->record($actor, $batch, 'actual_import.staged', null, ['file_name' => $fileName, 'file_checksum' => $checksum, ...$summary], null);

            return $batch;
        });
    }

    /**
     * Applies every valid row as a draft actual, or nothing at all.
     */
    public function commit(Actor $actor, int $batchId, int $expectedVersion, ActualAccess $access, ?string $lateReason): ActualImportBatch
    {
        $batch = ActualImportBatch::query()->findOrFail($batchId);

        $this->idempotency->execute(
            'actual_import.commit',
            $batch->public_id,
            $batch->file_checksum,
            fn (): array => $this->transactions->run(function () use ($actor, $batchId, $expectedVersion, $access, $lateReason): array {
                $batch = ActualImportBatch::query()->lockForUpdate()->findOrFail($batchId);

                if ($batch->status !== ActualImportBatch::STATUS_STAGED) {
                    throw new BusinessRuleViolation('import_not_staged', 'Import ini sudah di-commit atau dibatalkan.');
                }

                if ($batch->error_count > 0) {
                    throw new BusinessRuleViolation('import_has_errors', "Import berisi {$batch->error_count} baris error. Tidak ada baris yang di-commit; perbaiki file lalu unggah ulang.");
                }

                $rows = ActualImportRow::query()
                    ->where('actual_import_batch_id', $batch->id)
                    ->whereIn('status', [ActualImportRow::STATUS_VALID, ActualImportRow::STATUS_UNCHANGED])
                    ->orderBy('row_number')
                    ->get();

                if (! $rows->contains('status', ActualImportRow::STATUS_VALID)) {
                    throw new BusinessRuleViolation('import_nothing_to_commit', 'Tidak ada baris yang mengubah aktual.');
                }

                $targetIds = [];

                foreach ($rows as $row) {
                    if ($row->period_target_id !== null) {
                        $targetIds[$row->period_target_id] = $row->period_target_id;
                    }
                }

                PeriodTarget::query()->whereIn('id', array_values($targetIds))->orderBy('id')->lockForUpdate()->get();
                $states = $this->states(array_values($targetIds));
                $entries = [];
                $fileValues = [];

                foreach ($rows as $row) {
                    $key = "{$row->period_target_id}|{$row->window_start}|{$row->window_end}";

                    if ($row->period_target_id === null || $row->window_start === null || $row->window_end === null || ($states[$key] ?? 'none') !== $row->expected_state) {
                        throw new BusinessRuleViolation('import_stale', "Aktual pada baris {$row->row_number} berubah sejak file divalidasi. Tidak ada baris yang di-commit; unggah ulang file untuk validasi terbaru.");
                    }

                    if ($row->status !== ActualImportRow::STATUS_VALID) {
                        continue;
                    }

                    $fileValues[] = $row->actual_value;
                    $entries[] = new ActualEntry(
                        reference: (string) $row->row_number,
                        targetId: $row->period_target_id,
                        windowStart: $row->window_start,
                        windowEnd: $row->window_end,
                        value: $row->entry_kind === EntryKind::NotApplicable->value ? null : TargetValueRules::display($row->actual_value),
                        unit: (string) $row->unit,
                        note: $row->note,
                        sourceSystem: $row->source_system,
                        sourceReference: $row->source_reference,
                    );
                }

                try {
                    $result = $this->actuals->saveDrafts($actor, $batch->assessment_period_id, $access, $entries, $lateReason, KpiActualVersion::SOURCE_IMPORT, $batch->id);
                } catch (ActualEntriesRejected $rejected) {
                    $lines = implode(', ', array_keys($rejected->rowErrors));

                    throw new BusinessRuleViolation('import_stale', "Baris {$lines} tidak lagi valid. Tidak ada baris yang di-commit; unggah ulang file untuk validasi terbaru.");
                }

                // Reconciliation: what the file said against what was written.
                $written = KpiActualVersion::query()->where('actual_import_batch_id', $batch->id)->get(['actual_value']);
                $reconciliation = [
                    'file_rows' => $batch->row_count,
                    'rows_applied' => count($entries),
                    'actuals_written' => $written->count(),
                    'file_value_total' => self::total($fileValues),
                    'written_value_total' => self::total($written->pluck('actual_value')->all()),
                    ...$result,
                ];

                if ($reconciliation['rows_applied'] !== $reconciliation['actuals_written'] + $result['unchanged'] || $reconciliation['file_value_total'] !== $reconciliation['written_value_total']) {
                    throw new BusinessRuleViolation('import_reconciliation_failed', 'Rekonsiliasi import gagal: jumlah atau total nilai yang tersimpan tidak sama dengan isi file. Tidak ada baris yang di-commit.');
                }

                $batch = $this->lock->update($batch, $expectedVersion, [
                    'status' => ActualImportBatch::STATUS_COMMITTED,
                    'committed_by_user_id' => $actor->userId,
                    'committed_at' => CarbonImmutable::now(),
                    'summary' => json_encode([...($batch->summary ?? []), 'reconciliation' => $reconciliation], JSON_THROW_ON_ERROR),
                ]);

                $this->record($actor, $batch, 'actual_import.committed', ['status' => ActualImportBatch::STATUS_STAGED], [
                    'status' => ActualImportBatch::STATUS_COMMITTED,
                    'file_name' => $batch->file_name,
                    'file_checksum' => $batch->file_checksum,
                    ...$reconciliation,
                ], $lateReason);

                $this->outbox->publish(new OutboxEvent(
                    eventType: 'ImportCommitted',
                    eventVersion: 1,
                    aggregateType: 'actual_import_batch',
                    aggregateId: $batch->public_id,
                    payload: ['kind' => 'actual', 'period_id' => $batch->period->public_id, 'file_checksum' => $batch->file_checksum, ...$reconciliation],
                    deduplicationKey: "actual-import-committed:{$batch->public_id}",
                ));

                return $reconciliation;
            }),
        );

        return $batch->refresh();
    }

    public function discard(Actor $actor, int $batchId, int $expectedVersion): ActualImportBatch
    {
        return $this->transactions->run(function () use ($actor, $batchId, $expectedVersion): ActualImportBatch {
            $batch = ActualImportBatch::query()->lockForUpdate()->findOrFail($batchId);

            if ($batch->status !== ActualImportBatch::STATUS_STAGED) {
                throw new BusinessRuleViolation('import_not_staged', 'Import ini sudah di-commit atau dibatalkan.');
            }

            $batch = $this->lock->update($batch, $expectedVersion, [
                'status' => ActualImportBatch::STATUS_DISCARDED,
                'discarded_by_user_id' => $actor->userId,
                'discarded_at' => CarbonImmutable::now(),
            ]);

            $this->record($actor, $batch, 'actual_import.discarded', ['status' => ActualImportBatch::STATUS_STAGED], ['status' => ActualImportBatch::STATUS_DISCARDED], null);

            return $batch;
        });
    }

    /**
     * Rows with errors as a CSV the uploader can correct and send again.
     *
     * @return list<list<string>>
     */
    public function errorRows(ActualImportBatch $batch): array
    {
        $rows = [['row_number', ...ImportSheet::COLUMNS, 'errors']];

        foreach (ActualImportRow::query()->where('actual_import_batch_id', $batch->id)->where('status', ActualImportRow::STATUS_ERROR)->orderBy('row_number')->get() as $row) {
            $rows[] = [
                (string) $row->row_number,
                ...array_map(static fn (string $column): string => self::cell($row->raw[$column] ?? ''), ImportSheet::COLUMNS),
                self::cell(implode(' ', $row->errors ?? [])),
            ];
        }

        return $rows;
    }

    /**
     * Neutralizes spreadsheet formulas in exported text (CSV injection).
     */
    public static function cell(string $value): string
    {
        return ! is_numeric($value) && in_array(ltrim($value, " \t\r\n")[0] ?? '', ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }

    /**
     * @param  list<array{line: int, cells: array<string, string>}>  $records
     * @return list<StagedActualRow>
     */
    private function validate(AssessmentPeriod $period, ActualAccess $access, array $records): array
    {
        $index = [];
        $targets = $this->query->base($period, $access)->with('definitionVersion')->get();

        foreach ($targets as $target) {
            $index[self::key($target->kpi_code, $target->subject_type->value, $target->subject_code)][] = $target;
        }

        /** @var array<int, list<KpiActual>> $existing */
        $existing = [];

        foreach ($targets->chunk(1000) as $chunk) {
            foreach (KpiActual::query()->whereIn('period_target_id', $chunk->pluck('id')->all())->with('versions')->get() as $actual) {
                $existing[$actual->period_target_id][] = $actual;
            }
        }

        $rows = [];
        /** @var array<int, list<StagedActualRow>> $accepted rows already taking a window of a target */
        $accepted = [];
        /** @var array<string, StagedActualRow> $sources first row using a source record */
        $sources = [];

        foreach ($records as $record) {
            $cells = $record['cells'];
            $row = new StagedActualRow($record['line'], $cells);
            $rows[] = $row;

            $target = $this->resolveTarget($row, $index);

            if ($target === null) {
                continue;
            }

            $row->targetId = $target->id;
            $row->unit = ($cells['unit'] ?? '') === '' ? null : mb_substr($cells['unit'], 0, 30);
            $row->note = ($cells['note'] ?? '') === '' ? null : $cells['note'];
            $rawValue = $cells['actual_value'] ?? '';

            if ($rawValue === '') {
                $row->status = ActualImportRow::STATUS_SKIPPED;

                continue;
            }

            try {
                $row->window = ObservationWindow::for($target->definitionVersion->frequency, $cells['window_start'] ?? '', $cells['window_end'] ?? '', $period->starts_on, $period->ends_on);
            } catch (InvalidArgumentException $exception) {
                $row->reject($exception->getMessage());

                continue;
            }

            $this->checkSource($row, $cells, $sources, $existing[$target->id] ?? []);
            $this->checkValue($row, $target, $rawValue);

            $actual = null;

            foreach ($existing[$target->id] ?? [] as $candidate) {
                if ($candidate->window_start === $row->window->start && $candidate->window_end === $row->window->end) {
                    $actual = $candidate;
                } elseif ($row->window->overlaps($candidate->window_start, $candidate->window_end)) {
                    $row->reject("Rentang observasi bertumpang tindih dengan aktual yang sudah ada ({$candidate->windowLabel()}).");
                }
            }

            foreach ($accepted[$target->id] ?? [] as $other) {
                if ($other->window !== null && $other->window->overlaps($row->window->start, $row->window->end)) {
                    $row->reject("Duplikat: rentang observasi yang sama atau bertumpang tindih sudah ada pada baris {$other->line}.");
                    $other->reject("Duplikat: rentang observasi yang sama atau bertumpang tindih muncul lagi pada baris {$row->line}.");
                }
            }

            $accepted[$target->id][] = $row;
            $latest = $actual?->versions->last();

            if ($latest?->status === ActualStatus::Submitted) {
                $row->reject('Aktual sedang menunggu verifikasi dan tidak dapat diubah lewat file.');
            } elseif ($latest?->status === ActualStatus::Verified) {
                $row->reject('Aktual sudah terverifikasi. Perubahan memerlukan proses koreksi.');
            }

            if ($row->errors === []) {
                $row->expectedState = self::state($latest);
                $row->status = $latest !== null && $latest->status === ActualStatus::Draft && $latest->entry_kind === $row->kind && $latest->actual_value === $row->value && $latest->note === $row->note
                    ? ActualImportRow::STATUS_UNCHANGED
                    : ActualImportRow::STATUS_VALID;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, list<PeriodTarget>>  $index
     */
    private function resolveTarget(StagedActualRow $row, array $index): ?PeriodTarget
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
            : 'KPI ini memiliki lebih dari satu versi untuk subjek yang sama; isi aktual secara manual.');

        return null;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, StagedActualRow>  $sources
     * @param  list<KpiActual>  $existing
     */
    private function checkSource(StagedActualRow $row, array $cells, array &$sources, array $existing): void
    {
        $system = ($cells['source_system'] ?? '') === '' ? null : mb_substr($cells['source_system'], 0, 64);
        $reference = ($cells['source_reference'] ?? '') === '' ? null : mb_substr($cells['source_reference'], 0, 191);

        if (($system === null) !== ($reference === null)) {
            $row->reject('source_system dan source_reference harus diisi bersamaan.');

            return;
        }

        $row->sourceSystem = $system;
        $row->sourceReference = $reference;

        if ($system === null || $row->window === null) {
            return;
        }

        $key = "{$row->targetId}|{$system}|{$reference}";

        if (isset($sources[$key])) {
            $row->reject("Duplikat: record sumber yang sama sudah ada pada baris {$sources[$key]->line}.");
            $sources[$key]->reject("Duplikat: record sumber yang sama muncul lagi pada baris {$row->line}.");

            return;
        }

        $sources[$key] = $row;

        foreach ($existing as $candidate) {
            $sameWindow = $candidate->window_start === $row->window->start && $candidate->window_end === $row->window->end;
            $sameSource = $candidate->source_system === $system && $candidate->source_reference === $reference;

            if ($sameSource && ! $sameWindow) {
                $row->reject("Record sumber ini sudah diimpor sebagai aktual lain ({$candidate->windowLabel()}).");
            } elseif ($sameWindow && ! $sameSource) {
                $row->reject('Aktual untuk rentang ini sudah ada dengan referensi sumber yang berbeda atau tanpa referensi sumber.');
            }
        }
    }

    private function checkValue(StagedActualRow $row, PeriodTarget $target, string $rawValue): void
    {
        if (mb_strtoupper($rawValue) === self::NOT_APPLICABLE) {
            $row->kind = EntryKind::NotApplicable;

            if ($row->note === null) {
                $row->reject('Aktual N/A wajib disertai kolom note berisi alasan.');
            }

            return;
        }

        $row->kind = EntryKind::Value;

        try {
            $row->value = $target->valueRules()->named('aktual')->parse($rawValue, (string) $row->unit);
        } catch (InvalidArgumentException $exception) {
            $row->reject($exception->getMessage());
        }
    }

    /**
     * @param  list<int>  $targetIds
     * @return array<string, string> "target|start|end" => state
     */
    private function states(array $targetIds): array
    {
        $states = [];

        foreach (array_chunk($targetIds, 1000) as $chunk) {
            foreach (KpiActual::query()->whereIn('period_target_id', $chunk)->with('versions')->get() as $actual) {
                $states["{$actual->period_target_id}|{$actual->window_start}|{$actual->window_end}"] = self::state($actual->versions->last());
            }
        }

        return $states;
    }

    private static function state(?KpiActualVersion $version): string
    {
        return $version === null ? 'none' : "{$version->status->value}:{$version->id}:{$version->lock_version}";
    }

    private static function key(string $kpiCode, string $subjectType, string $subjectCode): string
    {
        return mb_strtoupper(trim($kpiCode)).'|'.$subjectType.'|'.mb_strtoupper(trim($subjectCode));
    }

    /**
     * Exact decimal sum of four-decimal strings. Whole and fractional parts
     * are added separately so a large file cannot overflow an integer.
     *
     * @param  array<array-key, string|null>  $values
     */
    private static function total(array $values): string
    {
        $whole = 0;
        $fraction = 0;

        foreach ($values as $value) {
            if ($value === null || preg_match('/^(-?)(\d+)\.(\d{4})$/', $value, $matches) !== 1) {
                continue;
            }

            $sign = $matches[1] === '-' ? -1 : 1;
            $whole += $sign * (int) $matches[2];
            $fraction += $sign * (int) $matches[3];
        }

        $whole += intdiv($fraction, 10_000);
        $fraction %= 10_000;

        if ($whole > 0 && $fraction < 0) {
            $whole--;
            $fraction += 10_000;
        } elseif ($whole < 0 && $fraction > 0) {
            $whole++;
            $fraction -= 10_000;
        }

        return sprintf('%s%d.%04d', $whole < 0 || $fraction < 0 ? '-' : '', abs($whole), abs($fraction));
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Actor $actor, ActualImportBatch $batch, string $action, ?array $before, ?array $after, ?string $reason): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'actual_import_batch',
            entityId: $batch->public_id,
            before: $before,
            after: $after,
            reason: $reason,
            context: $actor->audit,
        ));
    }
}
