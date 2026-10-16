<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationException;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationRun;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The exception register: every quarantined file, rejected batch,
 * reconciliation difference, migration conflict, and dead letter, with the
 * data owner who decides it. A decision (accepted as is, or resolved at the
 * source) keeps its reason and the person who made it.
 */
final readonly class ExceptionRegister
{
    use GuardsInput;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private AccessControl $access,
        private ConnectionRegistry $connections,
    ) {}

    /**
     * Details are safe summaries: counts, codes, and row numbers; never row
     * contents, personal data, or credentials.
     */
    public function record(?IntegrationRun $run, string $connection, string $kind, string $detail): IntegrationException
    {
        $owner = $this->connections->all()[$connection]->dataOwner ?? 'Belum ditetapkan';

        return IntegrationException::query()->create([
            'integration_run_id' => $run?->id,
            'connection' => $connection,
            'kind' => $kind,
            'detail' => mb_substr($detail, 0, 2000),
            'data_owner' => mb_substr($owner, 0, 160),
            'status' => IntegrationException::OPEN,
        ]);
    }

    public function decide(Actor $actor, User $user, int $exceptionId, string $decision, string $reason): IntegrationException
    {
        if (! $this->access->allows($user, Capability::IntegrationManage)) {
            throw new AuthorizationException;
        }

        if (! in_array($decision, [IntegrationException::ACCEPTED, IntegrationException::RESOLVED], true)) {
            throw new BusinessRuleViolation('invalid_decision', 'Keputusan harus diterima (accepted) atau diselesaikan di sumber (resolved).');
        }

        $reason = self::requireText($reason, 'reason', 'Alasan keputusan wajib diisi, termasuk siapa pemilik data yang menyetujui.', 1000);

        return $this->transactions->run(function () use ($actor, $user, $exceptionId, $decision, $reason): IntegrationException {
            $exception = IntegrationException::query()->lockForUpdate()->findOrFail($exceptionId);

            if ($exception->status !== IntegrationException::OPEN) {
                throw new BusinessRuleViolation('exception_decided', 'Exception ini sudah diputuskan.');
            }

            $exception->forceFill(['status' => $decision, 'resolution_reason' => $reason, 'resolved_by_user_id' => $user->id, 'resolved_at' => CarbonImmutable::now()])->save();
            $this->audit->record(new AuditEntry(
                action: 'integration_exception.decided',
                entityType: 'integration_exception',
                entityId: $exception->public_id,
                before: ['status' => IntegrationException::OPEN],
                after: ['status' => $decision, 'kind' => $exception->kind, 'connection' => $exception->connection],
                reason: $reason,
                context: $actor->audit,
            ));

            return $exception;
        });
    }
}
