<?php

declare(strict_types=1);

namespace App\Modules\Production\Application;

use App\Models\User;
use App\Modules\ActualEvidence\Application\ImportSheet;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Domain\ShiftCalendars;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionRecord;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use LogicException;

/**
 * Staged import of production, packing, delivery, warehouse, and work order
 * batches. Staging stores the rows and every problem found; nothing counts
 * until the batch is committed. The same file returns its existing batch. A
 * final batch never changes: late-arriving data or a source correction for
 * the same date is the next version, and the latest committed version is
 * the current one.
 */
final readonly class ProductionImportService
{
    use GuardsInput;

    public const MAX_ROWS = 20000;

    private const INSERT_CHUNK = 500;

    private const CONTROL = '/^\d{1,14}(?:\.\d{1,3})?$/';

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private ProductionAccess $access,
        private BusinessTime $time,
    ) {}

    /**
     * @param  array{quantity?: string|null, reject?: string|null, rework?: string|null}  $controls  totals the source reports for the file
     */
    public function stage(Actor $actor, User $user, int $unitId, BatchKind $kind, string $sourceSystem, string $coverageDate, string $extractedAt, string $originalName, string $path, string $format, array $controls = []): ProductionBatch
    {
        if ($actor->userId !== $user->id) {
            throw new LogicException('The audit actor and the importing account must be the same.');
        }

        if (! $this->access->canManage($user, $unitId, $kind)) {
            throw new AuthorizationException;
        }

        if (preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $sourceSystem) !== 1) {
            throw new BusinessRuleViolation('invalid_source', 'Kode sistem sumber hanya boleh huruf kecil, angka, garis bawah, dan tanda hubung.');
        }

        self::requirePeriod($coverageDate);
        $now = CarbonImmutable::now();
        $today = $this->time->businessDate($now);

        if ($coverageDate > $today) {
            throw new BusinessRuleViolation('coverage_in_future', "{$kind->coverageLabel()} tidak boleh di masa depan.");
        }

        $extracted = $this->extractedAt($extractedAt, $coverageDate, $now);
        $controls = $this->controls($kind, $controls);
        $calendars = $kind->usesShifts() ? $this->calendars() : ShiftCalendars::fromArray([]);

        if ($kind->usesShifts() && $calendars->isEmpty()) {
            throw new BusinessRuleViolation('shift_calendar_missing', 'Kalender shift belum disetujui (DEC-015), sehingga tanggal operasional dan shift tidak dapat ditentukan. Data berbasis shift belum dapat diimpor.');
        }

        $hash = (string) hash_file('sha256', $path);
        // The same file for the same team, kind, source, and date is the batch
        // that is staged or current (idempotent). The content of a superseded
        // version, or of a rejected batch, is staged again as a new version.
        $existing = ProductionBatch::query()
            ->where('organization_unit_id', $unitId)
            ->where('kind', $kind->value)
            ->where('source_system', $sourceSystem)
            ->where('coverage_date', $coverageDate)
            ->where('content_hash', $hash)
            ->where(fn ($query) => $query->where('status', ProductionBatch::STAGED)->orWhere(fn ($committed) => $committed->current()))
            ->orderByDesc('version')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $rows = ImportSheet::read($path, $format, self::MAX_ROWS, ProductionRowParser::COLUMNS[$kind->value], ProductionRowParser::REQUIRED[$kind->value]);
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRuleViolation('invalid_file', $exception->getMessage());
        }

        $parsed = (new ProductionRowParser($kind, $coverageDate, $calendars, new DateTimeZone($this->time->timezoneName()), $now))->parse($rows);
        $errors = [...$parsed['errors'], ...$this->countedElsewhere($unitId, $kind, $sourceSystem, $coverageDate, $parsed['records'])];

        try {
            return $this->transactions->run(function () use ($actor, $user, $unitId, $kind, $sourceSystem, $coverageDate, $extracted, $originalName, $hash, $parsed, $errors, $rows, $controls, $calendars): ProductionBatch {
                // Imports of a team are serialized on the team row, so two of
                // them never take the same version.
                $this->lockUnit($unitId);
                $version = (int) ProductionBatch::query()
                    ->where('organization_unit_id', $unitId)
                    ->where('kind', $kind->value)
                    ->where('source_system', $sourceSystem)
                    ->where('coverage_date', $coverageDate)
                    ->max('version') + 1;

                $batch = ProductionBatch::query()->create([
                    'organization_unit_id' => $unitId,
                    'kind' => $kind,
                    'source_system' => $sourceSystem,
                    'coverage_date' => $coverageDate,
                    'version' => $version,
                    'content_hash' => $hash,
                    'original_name' => mb_substr((string) preg_replace('/[^\w.\- ]/u', '_', basename($originalName)), 0, 160),
                    'status' => ProductionBatch::STAGED,
                    'row_count' => count($rows),
                    'error_count' => count($errors),
                    'errors' => array_slice($errors, 0, 200),
                    'missing_reason_count' => $parsed['missing_reason'],
                    'calendar_version' => $kind->usesShifts() ? $calendars->forDate($coverageDate)?->version : null,
                    'source_extracted_at' => $extracted,
                    'control_quantity_total' => $controls['quantity'],
                    'control_reject_total' => $controls['reject'],
                    'control_rework_total' => $controls['rework'],
                    'staged_by_user_id' => $user->id,
                ]);

                if ($errors === []) {
                    $this->insert($batch, $parsed['records']);
                }

                $this->record($actor, $batch, 'production_batch.staged', ['kind' => $kind->value, 'coverage_date' => $coverageDate, 'version' => $version, 'rows' => count($rows), 'errors' => count($errors), 'hash' => $hash]);

                return $batch;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleViolation('duplicate_file', 'File yang sama sedang diimpor untuk team, jenis data, dan tanggal ini.');
        }
    }

    /**
     * Commits a staged batch without errors whose totals match the control
     * totals given at staging. The previous current version of the same date
     * stays as history.
     */
    public function commit(Actor $actor, User $user, int $batchId): ProductionBatch
    {
        return $this->transactions->run(function () use ($actor, $user, $batchId): ProductionBatch {
            $batch = $this->staged($user, $batchId);

            if ($batch->error_count > 0) {
                throw new BusinessRuleViolation('batch_has_errors', 'Batch masih memiliki baris bermasalah. Perbaiki file lalu impor ulang.');
            }

            // Commits of a team are serialized like its imports, so two staged
            // batches that share an event never both count it.
            $this->lockUnit($batch->organization_unit_id);
            $this->assertNoNewerVersion($batch);
            $counted = $this->countedElsewhere($batch->organization_unit_id, $batch->kind, $batch->source_system, $batch->coverage_date, $this->eventsOf($batch));

            if ($counted !== []) {
                throw new BusinessRuleViolation('event_counted_elsewhere', 'Sebagian event sudah dihitung pada batch tanggal lain: '.implode(' ', array_slice($counted, 0, 5)));
            }

            $totals = ProductionQuery::totals($batch);
            $this->assertControls($batch, $totals);
            $previous = ProductionBatch::query()->current()
                ->where('organization_unit_id', $batch->organization_unit_id)
                ->where('kind', $batch->kind->value)
                ->where('source_system', $batch->source_system)
                ->where('coverage_date', $batch->coverage_date)
                ->first();

            $batch->forceFill(['status' => ProductionBatch::COMMITTED, 'committed_by_user_id' => $user->id, 'committed_at' => CarbonImmutable::now()])->save();
            $this->record($actor, $batch, 'production_batch.committed', [
                'kind' => $batch->kind->value,
                'coverage_date' => $batch->coverage_date,
                'version' => $batch->version,
                'supersedes' => $previous !== null && $previous->version < $batch->version ? $previous->public_id : null,
                'rows' => $totals['rows'],
                'quantity' => $totals['quantity'],
            ]);

            return $batch;
        });
    }

    public function reject(Actor $actor, User $user, int $batchId, string $reason): ProductionBatch
    {
        $reason = self::requireText($reason, 'reason', 'Alasan penolakan wajib diisi.', 1000);

        return $this->transactions->run(function () use ($actor, $user, $batchId, $reason): ProductionBatch {
            $batch = $this->staged($user, $batchId);
            $batch->forceFill(['status' => ProductionBatch::REJECTED])->save();
            $this->audit->record(new AuditEntry(
                action: 'production_batch.rejected',
                entityType: 'production_batch',
                entityId: $batch->public_id,
                before: ['status' => ProductionBatch::STAGED],
                after: ['status' => ProductionBatch::REJECTED],
                reason: $reason,
                context: $actor->audit,
            ));

            return $batch;
        });
    }

    private function extractedAt(string $value, string $coverageDate, CarbonImmutable $now): CarbonImmutable
    {
        $timezone = new DateTimeZone($this->time->timezoneName());
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', str_replace('T', ' ', $value), $timezone);

        if ($parsed === false || $parsed->format('Y-m-d H:i') !== str_replace('T', ' ', $value)) {
            throw new BusinessRuleViolation('invalid_extracted_at', 'Waktu ekstraksi data dari sistem sumber wajib diisi (YYYY-MM-DD HH:MM).');
        }

        if ($parsed > $now) {
            throw new BusinessRuleViolation('invalid_extracted_at', 'Waktu ekstraksi tidak boleh di masa depan.');
        }

        if ($parsed->format('Y-m-d') < $coverageDate) {
            throw new BusinessRuleViolation('invalid_extracted_at', 'Data tidak dapat diekstrak sebelum tanggal yang dicakupnya.');
        }

        return CarbonImmutable::instance($parsed)->utc();
    }

    /**
     * @param  array{quantity?: string|null, reject?: string|null, rework?: string|null}  $controls
     * @return array{quantity: string|null, reject: string|null, rework: string|null}
     */
    private function controls(BatchKind $kind, array $controls): array
    {
        $result = [];

        foreach (['quantity', 'reject', 'rework'] as $key) {
            $value = trim((string) ($controls[$key] ?? ''));

            if ($value !== '' && $key !== 'quantity' && $kind !== BatchKind::Production) {
                throw new BusinessRuleViolation('invalid_control_total', 'Total kontrol reject dan rework hanya untuk aktual produksi.');
            }

            if ($value !== '' && preg_match(self::CONTROL, $value) !== 1) {
                throw new BusinessRuleViolation('invalid_control_total', 'Total kontrol harus angka dengan titik desimal (maks. 3 desimal).');
            }

            $result[$key] = $value === '' ? null : $value;
        }

        return $result;
    }

    /**
     * Total control is compared per file, so a file with several units of
     * measure cannot be checked against one number.
     *
     * @param  array{quantity: array<string, string>, reject: array<string, string>, rework: array<string, string>, waste: array<string, string>, downtime_minutes: int, rows: array<string, int>}  $totals
     */
    private function assertControls(ProductionBatch $batch, array $totals): void
    {
        $mismatch = [];

        foreach (['quantity' => $batch->control_quantity_total, 'reject' => $batch->control_reject_total, 'rework' => $batch->control_rework_total] as $key => $control) {
            if ($control === null) {
                continue;
            }

            if (count($totals[$key]) > 1) {
                throw new BusinessRuleViolation('control_total_units', 'Total kontrol tidak dapat dibandingkan: file berisi beberapa satuan dan tidak ada konversi satuan yang disetujui.');
            }

            $actual = array_values($totals[$key])[0] ?? '0.000';

            if (! BigDecimal::of($control)->isEqualTo($actual)) {
                $mismatch[] = "{$key} {$actual} ≠ kontrol {$control}";
            }
        }

        if ($mismatch !== []) {
            throw new BusinessRuleViolation('control_total_mismatch', 'Total tidak cocok dengan total kontrol sumber: '.implode('; ', $mismatch).'.');
        }
    }

    /**
     * An output, packing, or delivery event is counted once: the same event
     * in the current batch of another date would be counted twice.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<string>
     */
    private function countedElsewhere(int $unitId, BatchKind $kind, string $sourceSystem, string $coverageDate, array $records): array
    {
        if (! $kind->countsEvents() || $records === []) {
            return [];
        }

        $lines = [];
        $byType = [];

        foreach ($records as $record) {
            $type = (string) $record['record_type'];
            $key = (string) $record['event_key'];
            $lines["{$type}|{$key}"] = $record['source_line'];
            $byType[$type][] = $key;
        }

        $current = ProductionBatch::query()->current()
            ->where('organization_unit_id', $unitId)
            ->where('kind', $kind->value)
            ->where('source_system', $sourceSystem)
            ->where('coverage_date', '!=', $coverageDate)
            ->pluck('coverage_date', 'id');

        if ($current->isEmpty()) {
            return [];
        }

        $errors = [];

        foreach ($byType as $type => $keys) {
            foreach (array_chunk($keys, 500) as $chunk) {
                // (record_type, event_key) is indexed; the batch filter narrows it to the current batches.
                $found = ProductionRecord::query()->toBase()
                    ->where('record_type', $type)
                    ->whereIn('event_key', $chunk)
                    ->whereIn('production_batch_id', $current->keys()->all())
                    ->get(['event_key', 'production_batch_id']);

                foreach ($found as $row) {
                    $errors[] = "Baris {$lines["{$type}|{$row->event_key}"]}: event {$row->event_key} sudah dihitung pada batch tanggal {$current[$row->production_batch_id]}.";
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<array{record_type: string, event_key: string, source_line: int}>
     */
    private function eventsOf(ProductionBatch $batch): array
    {
        return array_values(ProductionRecord::query()->where('production_batch_id', $batch->id)->toBase()->get(['record_type', 'event_key', 'source_line'])
            ->map(fn (object $row): array => ['record_type' => (string) $row->record_type, 'event_key' => (string) $row->event_key, 'source_line' => (int) $row->source_line])
            ->all());
    }

    /**
     * An older version committed after a newer one would never be current
     * and would hide that the newer data was already accepted.
     */
    private function assertNoNewerVersion(ProductionBatch $batch): void
    {
        $newer = ProductionBatch::query()
            ->where('organization_unit_id', $batch->organization_unit_id)
            ->where('kind', $batch->kind->value)
            ->where('source_system', $batch->source_system)
            ->where('coverage_date', $batch->coverage_date)
            ->where('status', ProductionBatch::COMMITTED)
            ->where('version', '>', $batch->version)
            ->exists();

        if ($newer) {
            throw new BusinessRuleViolation('newer_version_committed', 'Versi yang lebih baru untuk tanggal ini sudah di-commit. Tolak batch ini atau impor ulang data terbaru.');
        }
    }

    /**
     * Serializes the imports and commits of a team. FOR NO KEY UPDATE does
     * not block inserts elsewhere that only reference the team row.
     */
    private function lockUnit(int $unitId): void
    {
        OrganizationUnit::query()->whereKey($unitId)->lock('for no key update')->firstOrFail();
    }

    private function calendars(): ShiftCalendars
    {
        try {
            return ProductionQuery::calendars();
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRuleViolation('shift_calendar_invalid', 'Konfigurasi kalender shift tidak valid: '.$exception->getMessage());
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function insert(ProductionBatch $batch, array $records): void
    {
        $now = CarbonImmutable::now();
        $columns = ['source_line', 'record_type', 'event_key', 'work_order', 'product_code', 'machine_code', 'customer_code', 'shift_code', 'operational_date', 'due_date', 'started_at', 'ended_at', 'unit', 'quantity', 'reject_quantity', 'rework_quantity', 'downtime_minutes', 'reason_code', 'defect_code', 'location_category', 'details', 'row_hash'];

        foreach (array_chunk($records, self::INSERT_CHUNK) as $chunk) {
            ProductionRecord::query()->insert(array_map(static function (array $record) use ($batch, $columns, $now): array {
                $row = [];

                foreach ($columns as $column) {
                    $value = $record[$column] ?? null;
                    $row[$column] = match (true) {
                        $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->utc(),
                        is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
                        default => $value,
                    };
                }

                return [...$row, 'production_batch_id' => $batch->id, 'created_at' => $now, 'updated_at' => $now];
            }, $chunk));
        }
    }

    private function staged(User $user, int $batchId): ProductionBatch
    {
        $batch = ProductionBatch::query()->lockForUpdate()->findOrFail($batchId);

        if (! $this->access->canManage($user, $batch->organization_unit_id, $batch->kind)) {
            throw new AuthorizationException;
        }

        if ($batch->status !== ProductionBatch::STAGED) {
            throw new BusinessRuleViolation('batch_not_staged', 'Batch ini sudah diputuskan.');
        }

        return $batch;
    }

    /**
     * Audit names the batch and its totals, not row data.
     *
     * @param  array<string, mixed>  $after
     */
    private function record(Actor $actor, ProductionBatch $batch, string $action, array $after): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'production_batch',
            entityId: $batch->public_id,
            before: null,
            after: $after,
            reason: null,
            context: $actor->audit,
        ));
    }
}
