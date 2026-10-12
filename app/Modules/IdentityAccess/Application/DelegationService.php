<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\Delegation;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditContext;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Time-boxed delegation of an approval-chain role (ROLE_APPROVAL_MATRIX.md
 * §Delegasi): it has a giver, receiver, scope, reason, start, mandatory end,
 * the account that authorized it, and an audit trail. It may not exceed the
 * giver's own grant, start in the past, or be given to oneself.
 */
final readonly class DelegationService
{
    use GuardsInput;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OptimisticLock $lock,
        private OrganizationDirectory $directory,
        private AccessControl $access,
        private BusinessTime $time,
    ) {}

    public function create(
        Actor $actor,
        int $sourceAssignmentId,
        int $delegateUserId,
        int $unitId,
        string $effectiveFrom,
        string $effectiveUntil,
        string $reason,
    ): Delegation {
        $reason = self::requireText($reason, 'reason', 'Alasan delegasi wajib diisi.');
        $period = self::requirePeriod($effectiveFrom, $effectiveUntil);

        $delegation = $this->transactions->run(function () use ($actor, $sourceAssignmentId, $delegateUserId, $unitId, $period, $reason): Delegation {
            $source = RoleAssignment::query()->lockForUpdate()->findOrFail($sourceAssignmentId);
            $delegate = User::query()->findOrFail($delegateUserId);
            $today = $this->time->businessDate(CarbonImmutable::now());

            if (! $source->role->isDelegable()) {
                throw new BusinessRuleViolation('role_not_delegable', "Role {$source->role->label()} tidak dapat didelegasikan.");
            }

            if ($delegate->id === $source->user_id) {
                throw new BusinessRuleViolation('self_delegation', 'Delegasi tidak dapat diberikan kepada pemilik role sendiri.');
            }

            if ($delegate->id === $actor->userId) {
                throw new BusinessRuleViolation('self_grant', 'Anda tidak dapat memberikan delegasi kepada diri sendiri.');
            }

            if (! $delegate->isActive()) {
                throw new BusinessRuleViolation('account_inactive', 'Penerima delegasi harus memiliki akun aktif.');
            }

            if ($period->effectiveFrom < $today) {
                throw new BusinessRuleViolation('retroactive_delegation', 'Delegasi tidak dapat dimulai sebelum hari ini.');
            }

            if ($source->revoked_at !== null
                || $source->effective_from > $period->effectiveFrom
                || ($source->effective_until !== null && $source->effective_until < (string) $period->effectiveUntil)) {
                throw new BusinessRuleViolation('exceeds_source_period', 'Periode delegasi harus berada di dalam periode role pemberi delegasi.');
            }

            $unitCode = $this->assertWithinSourceScope($source, $unitId, $period->effectiveFrom);

            $overlaps = Delegation::query()
                ->where('source_role_assignment_id', $source->id)
                ->where('delegate_user_id', $delegate->id)
                ->where('organization_unit_id', $unitId)
                ->whereNull('revoked_at')
                ->where('effective_from', '<', $period->effectiveUntil)
                ->where('effective_until', '>', $period->effectiveFrom)
                ->exists();

            if ($overlaps) {
                throw new BusinessRuleViolation('delegation_overlap', 'Delegasi yang sama sudah ada pada periode tersebut.');
            }

            $delegation = Delegation::query()->create([
                'source_role_assignment_id' => $source->id,
                'delegator_user_id' => $source->user_id,
                'delegate_user_id' => $delegate->id,
                'role' => $source->role,
                'organization_unit_id' => $unitId,
                'effective_from' => $period->effectiveFrom,
                'effective_until' => $period->effectiveUntil,
                'reason' => $reason,
                'granted_by_user_id' => $actor->userId,
            ]);

            $this->audit->record(new AuditEntry(
                action: 'delegation.created',
                entityType: 'delegation',
                entityId: $delegation->public_id,
                before: null,
                after: [
                    'source_role_assignment_id' => $source->public_id,
                    'delegate_user_id' => $delegate->public_id,
                    'role' => $source->role->value,
                    'scope' => $unitCode,
                    'effective_from' => $period->effectiveFrom,
                    'effective_until' => $period->effectiveUntil,
                ],
                reason: $reason,
                context: $actor->audit,
            ));

            return $delegation;
        });

        $this->access->flush();

        return $delegation;
    }

    public function revoke(Actor $actor, int $delegationId, int $expectedVersion, string $reason): Delegation
    {
        $reason = self::requireText($reason, 'reason', 'Alasan pencabutan wajib diisi.');

        $delegation = $this->transactions->run(function () use ($actor, $delegationId, $expectedVersion, $reason): Delegation {
            $delegation = Delegation::query()->findOrFail($delegationId);
            $now = CarbonImmutable::now();

            if ($delegation->revoked_at !== null) {
                throw new BusinessRuleViolation('already_revoked', 'Delegasi sudah dicabut.');
            }

            if ($delegation->effective_until <= $this->time->businessDate($now)) {
                throw new BusinessRuleViolation('history_locked', 'Delegasi yang sudah kedaluwarsa tidak dapat dicabut.');
            }

            $delegation = $this->lock->update($delegation, $expectedVersion, [
                'revoked_at' => $now,
                'revoked_by_user_id' => $actor->userId,
                'revoke_reason' => $reason,
            ]);

            $this->audit->record(new AuditEntry(
                action: 'delegation.revoked',
                entityType: 'delegation',
                entityId: $delegation->public_id,
                before: ['revoked_at' => null],
                after: ['revoked_at' => $now->toIso8601String()],
                reason: $reason,
                context: $actor->audit,
            ));

            return $delegation;
        });

        $this->access->flush();

        return $delegation;
    }

    /**
     * Writes one expiry audit per delegation whose end date has passed.
     * Authorization already ignores expired delegations; this only makes the
     * expiry visible in the audit trail. Safe to run repeatedly.
     */
    public function recordExpirations(CarbonImmutable $now, int $limit = 500): int
    {
        $today = $this->time->businessDate($now);
        $recorded = 0;

        $ids = Delegation::query()
            ->where('effective_until', '<=', $today)
            ->whereNull('expiry_recorded_at')
            ->where(fn (Builder $query) => $query->whereNull('revoked_at')->orWhere('revoked_at', '>', $now))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id);

        foreach ($ids as $id) {
            $recorded += $this->transactions->run(function () use ($id, $now, $today): int {
                $delegation = Delegation::query()->lockForUpdate()->find($id);

                if ($delegation === null || $delegation->expiry_recorded_at !== null || $delegation->effective_until > $today) {
                    return 0;
                }

                $delegation->forceFill(['expiry_recorded_at' => $now])->save();

                $this->audit->record(new AuditEntry(
                    action: 'delegation.expired',
                    entityType: 'delegation',
                    entityId: $delegation->public_id,
                    before: null,
                    after: ['effective_until' => $delegation->effective_until],
                    reason: 'Masa berlaku delegasi berakhir.',
                    context: AuditContext::system('scheduler', 'identity:expire-delegations'),
                ));

                return 1;
            });
        }

        return $recorded;
    }

    private function assertWithinSourceScope(RoleAssignment $source, int $unitId, string $businessDate): string
    {
        $snapshot = $this->directory->snapshot($businessDate);
        $unit = $snapshot->unit($unitId);
        $sourceUnit = $source->organization_unit_id === null ? null : $snapshot->unit($source->organization_unit_id);

        $withinScope = $unit !== null && $sourceUnit !== null
            && ($sourceUnit->type === UnitType::Company || $snapshot->contains($sourceUnit->id, $unit->id));

        if (! $withinScope) {
            throw new BusinessRuleViolation('scope_exceeded', 'Scope delegasi tidak boleh melebihi scope role pemberi delegasi.');
        }

        return $unit->code;
    }
}
