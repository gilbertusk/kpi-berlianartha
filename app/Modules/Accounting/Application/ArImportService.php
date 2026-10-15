<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Models\User;
use App\Modules\Accounting\Infrastructure\Persistence\Models\ArDocument;
use App\Modules\Accounting\Infrastructure\Persistence\Models\ArSnapshot;
use App\Modules\ActualEvidence\Application\ImportSheet;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use LogicException;

/**
 * Staged import of receivables snapshots. Staging stores the file's rows and
 * every problem found; nothing counts until the snapshot is committed. The
 * same file is recognised by its content hash and returns its existing
 * snapshot. A committed snapshot never changes; a corrected file for the
 * same as-of date becomes the next version, and both stay.
 */
final readonly class ArImportService
{
    use GuardsInput;

    public const MAX_ROWS = 20000;

    private const INSERT_CHUNK = 500;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private ArAccess $access,
        private BusinessTime $time,
    ) {}

    public function stage(Actor $actor, User $user, int $unitId, string $sourceSystem, string $asOf, string $originalName, string $path, string $format, ?string $controlInvoiceTotal = null, ?string $controlAllocationTotal = null): ArSnapshot
    {
        if ($actor->userId !== $user->id) {
            throw new LogicException('The audit actor and the importing account must be the same.');
        }

        if (! $this->access->canManage($user, $unitId)) {
            throw new AuthorizationException;
        }

        if (preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $sourceSystem) !== 1) {
            throw new BusinessRuleViolation('invalid_source', 'Kode sistem sumber hanya boleh huruf kecil, angka, garis bawah, dan tanda hubung.');
        }

        self::requirePeriod($asOf);

        if ($asOf > $this->time->businessDate(CarbonImmutable::now())) {
            throw new BusinessRuleViolation('as_of_in_future', 'Tanggal as-of tidak boleh di masa depan.');
        }

        foreach ([$controlInvoiceTotal, $controlAllocationTotal] as $control) {
            if ($control !== null && $control !== '' && preg_match('/^\d{1,16}(?:\.\d{1,2})?$/', $control) !== 1) {
                throw new BusinessRuleViolation('invalid_control_total', 'Total kontrol harus angka dengan titik desimal (maks. 2 desimal).');
            }
        }

        $hash = (string) hash_file('sha256', $path);
        $existing = ArSnapshot::query()
            ->where('source_system', $sourceSystem)
            ->where('content_hash', $hash)
            ->where('status', '!=', ArSnapshot::REJECTED)
            ->first();

        if ($existing !== null) {
            // The same file again is the same snapshot (idempotent), but not
            // for another team or as-of date: that is a mistake, not a retry.
            if ($existing->organization_unit_id !== $unitId || $existing->as_of_date !== $asOf || ! $this->access->canRead($user, $existing)) {
                throw new BusinessRuleViolation('duplicate_file', 'File ini sudah pernah diimpor untuk team atau tanggal as-of lain.');
            }

            return $existing;
        }

        try {
            $records = ImportSheet::read($path, $format, self::MAX_ROWS, ArRowParser::COLUMNS, ArRowParser::REQUIRED);
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRuleViolation('invalid_file', $exception->getMessage());
        }

        $parsed = ArRowParser::parse($records, $asOf);

        try {
            return $this->transactions->run(function () use ($actor, $user, $unitId, $sourceSystem, $asOf, $originalName, $hash, $parsed, $records, $controlInvoiceTotal, $controlAllocationTotal): ArSnapshot {
                // FOR UPDATE is not allowed with an aggregate, and locks nothing
                // before the first version: imports of a team are serialized on
                // the team row instead, so two of them never take one version.
                OrganizationUnit::query()->whereKey($unitId)->lockForUpdate()->firstOrFail();
                $version = (int) ArSnapshot::query()
                    ->where('organization_unit_id', $unitId)
                    ->where('source_system', $sourceSystem)
                    ->where('as_of_date', $asOf)
                    ->max('version') + 1;

                $snapshot = ArSnapshot::query()->create([
                    'organization_unit_id' => $unitId,
                    'source_system' => $sourceSystem,
                    'as_of_date' => $asOf,
                    'version' => $version,
                    'content_hash' => $hash,
                    'original_name' => mb_substr((string) preg_replace('/[^\w.\- ]/u', '_', basename($originalName)), 0, 160),
                    'status' => ArSnapshot::STAGED,
                    'row_count' => count($records),
                    'error_count' => count($parsed['errors']),
                    'errors' => array_slice($parsed['errors'], 0, 200),
                    'control_invoice_total' => $controlInvoiceTotal === '' ? null : $controlInvoiceTotal,
                    'control_allocation_total' => $controlAllocationTotal === '' ? null : $controlAllocationTotal,
                    'staged_by_user_id' => $user->id,
                ]);

                if ($parsed['errors'] === []) {
                    $now = CarbonImmutable::now();

                    foreach (array_chunk($parsed['documents'], self::INSERT_CHUNK) as $chunk) {
                        ArDocument::query()->insert(array_map(static fn (array $document): array => [
                            ...$document,
                            'ar_snapshot_id' => $snapshot->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ], $chunk));
                    }
                }

                $this->record($actor, $snapshot, 'ar_snapshot.staged', ['as_of' => $asOf, 'version' => $version, 'rows' => count($records), 'errors' => count($parsed['errors']), 'hash' => $hash]);

                return $snapshot;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleViolation('duplicate_file', 'File ini sudah pernah diimpor.');
        }
    }

    /**
     * Commits a staged snapshot without errors whose totals match the control
     * totals given at staging.
     */
    public function commit(Actor $actor, User $user, int $snapshotId): ArSnapshot
    {
        return $this->transactions->run(function () use ($actor, $user, $snapshotId): ArSnapshot {
            $snapshot = $this->staged($user, $snapshotId);

            if ($snapshot->error_count > 0) {
                throw new BusinessRuleViolation('snapshot_has_errors', 'Snapshot masih memiliki baris bermasalah. Perbaiki file lalu impor ulang.');
            }

            $totals = ArQuery::totals($snapshot->id);
            $mismatch = [];

            if ($snapshot->control_invoice_total !== null && ! BigDecimal::of($snapshot->control_invoice_total)->isEqualTo($totals['invoice_total'])) {
                $mismatch[] = "total invoice {$totals['invoice_total']} ≠ kontrol {$snapshot->control_invoice_total}";
            }

            if ($snapshot->control_allocation_total !== null && ! BigDecimal::of($snapshot->control_allocation_total)->isEqualTo($totals['allocation_total'])) {
                $mismatch[] = "total alokasi {$totals['allocation_total']} ≠ kontrol {$snapshot->control_allocation_total}";
            }

            if ($mismatch !== []) {
                throw new BusinessRuleViolation('control_total_mismatch', 'Total tidak cocok dengan total kontrol sumber: '.implode('; ', $mismatch).'.');
            }

            $snapshot->forceFill(['status' => ArSnapshot::COMMITTED, 'committed_by_user_id' => $user->id, 'committed_at' => CarbonImmutable::now()])->save();
            $this->record($actor, $snapshot, 'ar_snapshot.committed', ['as_of' => $snapshot->as_of_date, 'version' => $snapshot->version, ...$totals]);

            return $snapshot;
        });
    }

    public function reject(Actor $actor, User $user, int $snapshotId, string $reason): ArSnapshot
    {
        $reason = self::requireText($reason, 'reason', 'Alasan penolakan wajib diisi.', 1000);

        return $this->transactions->run(function () use ($actor, $user, $snapshotId, $reason): ArSnapshot {
            $snapshot = $this->staged($user, $snapshotId);
            $snapshot->forceFill(['status' => ArSnapshot::REJECTED])->save();
            $this->audit->record(new AuditEntry(
                action: 'ar_snapshot.rejected',
                entityType: 'ar_snapshot',
                entityId: $snapshot->public_id,
                before: ['status' => ArSnapshot::STAGED],
                after: ['status' => ArSnapshot::REJECTED],
                reason: $reason,
                context: $actor->audit,
            ));

            return $snapshot;
        });
    }

    private function staged(User $user, int $snapshotId): ArSnapshot
    {
        $snapshot = ArSnapshot::query()->lockForUpdate()->findOrFail($snapshotId);

        if (! $this->access->canManage($user, $snapshot->organization_unit_id)) {
            throw new AuthorizationException;
        }

        if ($snapshot->status !== ArSnapshot::STAGED) {
            throw new BusinessRuleViolation('snapshot_not_staged', 'Snapshot ini sudah diputuskan.');
        }

        return $snapshot;
    }

    /**
     * Audit names the snapshot and its totals, not customer data.
     *
     * @param  array<string, mixed>  $after
     */
    private function record(Actor $actor, ArSnapshot $snapshot, string $action, array $after): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'ar_snapshot',
            entityId: $snapshot->public_id,
            before: null,
            after: $after,
            reason: null,
            context: $actor->audit,
        ));
    }
}
