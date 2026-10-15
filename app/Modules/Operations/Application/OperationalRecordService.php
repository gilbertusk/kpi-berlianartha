<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\Operations\Infrastructure\Persistence\Models\OperationalRecord;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use LogicException;

/**
 * Keeps the operational register: records entered by a team or delivered
 * by a source adapter, and voids. The audit log names the record and its
 * source, not its figures, so cost figures do not reach readers of the
 * audit trail who may not read them here.
 */
final readonly class OperationalRecordService
{
    use GuardsInput;

    public const MANUAL = 'manual';

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OptimisticLock $lock,
        private RecordTypeRegistry $types,
        private OperationalAccess $access,
        private OrganizationDirectory $directory,
        private BusinessTime $time,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(Actor $actor, User $user, string $type, string $unitCode, string $occurredOn, array $attributes, ?string $kpiCode = null, string $sourceSystem = self::MANUAL): OperationalRecord
    {
        if ($actor->userId !== $user->id) {
            throw new LogicException('The audit actor and the recording account must be the same.');
        }

        $recordType = $this->types->get($type);
        self::requirePeriod($occurredOn);

        if ($occurredOn > $this->time->businessDate(CarbonImmutable::now())) {
            throw new BusinessRuleViolation('date_in_future', 'Tanggal kejadian tidak boleh di masa depan.');
        }

        try {
            $values = $recordType->normalize($attributes);
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRuleViolation('invalid_record', $exception->getMessage());
        }

        $unitId = $this->unitId($unitCode);

        if (! $this->access->canManage($user, $unitId)) {
            throw new AuthorizationException;
        }

        $kpiId = null;

        if ($kpiCode !== null && trim($kpiCode) !== '') {
            $kpiId = KpiDefinition::query()->where('code', strtoupper(trim($kpiCode)))->value('id')
                ?? throw new BusinessRuleViolation('kpi_not_found', 'Kode KPI tidak ditemukan.');
        }

        try {
            return $this->transactions->run(function () use ($actor, $user, $recordType, $unitId, $occurredOn, $values, $kpiId, $sourceSystem): OperationalRecord {
                $record = OperationalRecord::query()->create([
                    'record_type' => $recordType->key(),
                    'organization_unit_id' => $unitId,
                    'occurred_on' => $occurredOn,
                    'source_system' => $sourceSystem,
                    'source_reference' => $this->reference($recordType->sourceReference($values)),
                    'kpi_definition_id' => $kpiId,
                    'details' => $values,
                    'recorded_by_user_id' => $user->id,
                ]);

                $this->audit->record(new AuditEntry(
                    action: 'operational_record.recorded',
                    entityType: 'operational_record',
                    entityId: $record->public_id,
                    before: null,
                    after: ['type' => $record->record_type, 'unit_id' => $unitId, 'occurred_on' => $occurredOn, 'source' => "{$sourceSystem}:{$record->source_reference}"],
                    reason: null,
                    context: $actor->audit,
                ));

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleViolation('duplicate_source', 'Team ini sudah memiliki catatan dengan referensi sumber yang sama. Batalkan catatan lama bila perlu mencatat ulang.');
        }
    }

    /**
     * Registers what a source adapter delivers. A record already in the
     * register is skipped, so running an import again adds nothing.
     *
     * @return array{recorded: int, skipped: int, rejected: list<string>}
     */
    public function ingest(Actor $actor, User $user, OperationalRecordSource $source): array
    {
        if (preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $source->key()) !== 1 || $source->key() === self::MANUAL) {
            throw new LogicException('A source adapter needs its own key.');
        }

        $result = ['recorded' => 0, 'skipped' => 0, 'rejected' => []];
        $line = 0;

        foreach ($source->records() as $item) {
            $line++;

            try {
                $this->record($actor, $user, $item->recordType, $item->unitCode, $item->occurredOn, $item->attributes, $item->kpiCode, $source->key());
                $result['recorded']++;
            } catch (AuthorizationException) {
                // A record for a team outside the importer's scope is refused, not stored.
                $result['rejected'][] = "Baris {$line}: team di luar scope Anda.";
            } catch (BusinessRuleViolation $violation) {
                if ($violation->rule === 'duplicate_source') {
                    $result['skipped']++;
                } else {
                    $result['rejected'][] = "Baris {$line}: {$violation->safeMessage()}";
                }
            }
        }

        return $result;
    }

    public function void(Actor $actor, User $user, int $recordId, int $expectedVersion, string $reason): OperationalRecord
    {
        if ($actor->userId !== $user->id) {
            throw new LogicException('The audit actor and the voiding account must be the same.');
        }

        $reason = self::requireText($reason, 'reason', 'Alasan pembatalan wajib diisi.', 1000);

        return $this->transactions->run(function () use ($actor, $user, $recordId, $expectedVersion, $reason): OperationalRecord {
            $record = OperationalRecord::query()->lockForUpdate()->findOrFail($recordId);

            if (! $this->access->canManage($user, $record->organization_unit_id)) {
                throw new AuthorizationException;
            }

            if ($record->voided_at !== null) {
                throw new BusinessRuleViolation('already_voided', 'Catatan ini sudah dibatalkan.');
            }

            $record = $this->lock->update($record, $expectedVersion, [
                'voided_at' => CarbonImmutable::now(),
                'voided_by_user_id' => $user->id,
                'void_reason' => $reason,
            ]);

            $this->audit->record(new AuditEntry(
                action: 'operational_record.voided',
                entityType: 'operational_record',
                entityId: $record->public_id,
                before: ['voided' => false],
                after: ['voided' => true, 'type' => $record->record_type, 'source' => "{$record->source_system}:{$record->source_reference}"],
                reason: $reason,
                context: $actor->audit,
            ));

            return $record;
        });
    }

    private function reference(string $reference): string
    {
        if (mb_strlen($reference) > 160) {
            throw new BusinessRuleViolation('invalid_record', 'Referensi sumber terlalu panjang.');
        }

        return $reference;
    }

    private function unitId(string $code): int
    {
        $code = strtoupper(trim($code));

        foreach ($this->directory->snapshot($this->time->businessDate(CarbonImmutable::now()))->all() as $unit) {
            if ($unit->code === $code) {
                return $unit->id;
            }
        }

        // An unknown unit is refused like a unit out of scope.
        throw new AuthorizationException;
    }
}
