<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\ScopeKind;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\Delegation;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Shared\Application\Audit\Actor;
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
 * Grants and revokes roles. A role is always bound to an explicit scope and
 * effective period that starts today or later; nobody may change their own
 * access or give business roles to an account they provisioned.
 */
final readonly class RoleAssignmentService
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

    public function grant(
        Actor $actor,
        int $userId,
        Role $role,
        ?int $unitId,
        string $effectiveFrom,
        ?string $effectiveUntil,
        string $reason,
    ): RoleAssignment {
        $reason = self::requireText($reason, 'reason', 'Alasan pemberian role wajib diisi.');
        $period = self::requirePeriod($effectiveFrom, $effectiveUntil);
        self::requireNotBefore($period->effectiveFrom, $this->time->businessDate(CarbonImmutable::now()));

        if ($actor->userId === $userId) {
            throw new BusinessRuleViolation('self_grant', 'Anda tidak dapat mengubah hak akses akun sendiri.');
        }

        $assignment = $this->transactions->run(function () use ($actor, $userId, $role, $unitId, $period, $reason): RoleAssignment {
            $user = User::query()->lockForUpdate()->findOrFail($userId);

            if (! $user->isActive()) {
                throw new BusinessRuleViolation('account_inactive', 'Role tidak dapat diberikan ke akun nonaktif.');
            }

            // Two-person rule: an administrator cannot provision an account
            // and then give it business authority (a "puppet" account).
            if ($role !== Role::Employee && $actor->userId !== null && $user->provisioned_by_user_id === $actor->userId) {
                throw new BusinessRuleViolation('provisioner_grant', 'Role bisnis untuk akun yang Anda buat harus diberikan oleh System Admin lain.');
            }

            $unitCode = $this->assertScope($role, $unitId, $period->effectiveFrom);

            if ($role === Role::Employee && ! Employee::query()->where('user_id', $user->id)->exists()) {
                throw new BusinessRuleViolation('employee_link_required', 'Role Employee membutuhkan akun yang terhubung dengan data pegawai.');
            }

            $overlaps = RoleAssignment::query()
                ->where('user_id', $user->id)
                ->where('role', $role->value)
                ->where(fn (Builder $query) => $unitId === null
                    ? $query->whereNull('organization_unit_id')
                    : $query->where('organization_unit_id', $unitId))
                ->whereNull('revoked_at')
                ->where(fn (Builder $query) => $period->effectiveUntil === null
                    ? $query
                    : $query->where('effective_from', '<', $period->effectiveUntil))
                ->where(fn (Builder $query) => $query
                    ->whereNull('effective_until')
                    ->orWhere('effective_until', '>', $period->effectiveFrom))
                ->exists();

            if ($overlaps) {
                throw new BusinessRuleViolation('assignment_overlap', 'Akun sudah memiliki role yang sama untuk scope dan periode tersebut.');
            }

            $assignment = RoleAssignment::query()->create([
                'user_id' => $user->id,
                'role' => $role,
                'organization_unit_id' => $unitId,
                'effective_from' => $period->effectiveFrom,
                'effective_until' => $period->effectiveUntil,
                'reason' => $reason,
                'granted_by_user_id' => $actor->userId,
            ]);

            $this->audit->record(new AuditEntry(
                action: 'role.granted',
                entityType: 'role_assignment',
                entityId: $assignment->public_id,
                before: null,
                after: [
                    'user_id' => $user->public_id,
                    'role' => $role->value,
                    'scope' => $unitCode ?? $role->scopeKind()->value,
                    'effective_from' => $period->effectiveFrom,
                    'effective_until' => $period->effectiveUntil,
                ],
                reason: $reason,
                context: $actor->audit,
            ));

            return $assignment;
        });

        $this->access->flush();

        return $assignment;
    }

    public function revoke(Actor $actor, int $assignmentId, int $expectedVersion, string $reason): RoleAssignment
    {
        $reason = self::requireText($reason, 'reason', 'Alasan pencabutan wajib diisi.');

        $assignment = $this->transactions->run(function () use ($actor, $assignmentId, $expectedVersion, $reason): RoleAssignment {
            $assignment = RoleAssignment::query()->findOrFail($assignmentId);
            $now = CarbonImmutable::now();
            $today = $this->time->businessDate($now);

            if ($assignment->user_id === $actor->userId) {
                throw new BusinessRuleViolation('self_grant', 'Anda tidak dapat mengubah hak akses akun sendiri.');
            }

            if ($assignment->revoked_at !== null) {
                throw new BusinessRuleViolation('already_revoked', 'Role sudah dicabut.');
            }

            if ($assignment->effective_until !== null && $assignment->effective_until <= $today) {
                throw new BusinessRuleViolation('history_locked', 'Role yang sudah berakhir tidak dapat dicabut.');
            }

            $assignment = $this->lock->update($assignment, $expectedVersion, [
                'revoked_at' => $now,
                'revoked_by_user_id' => $actor->userId,
                'revoke_reason' => $reason,
            ]);

            $this->audit->record(new AuditEntry(
                action: 'role.revoked',
                entityType: 'role_assignment',
                entityId: $assignment->public_id,
                before: ['revoked_at' => null],
                after: ['revoked_at' => $now->toIso8601String()],
                reason: $reason,
                context: $actor->audit,
            ));

            $this->revokeDependentDelegations($actor, $assignment, $now, $today);

            return $assignment;
        });

        $this->access->flush();

        return $assignment;
    }

    /**
     * Delegations already lapse when their source is revoked; marking them
     * revoked as well keeps the access review and audit trail explicit.
     */
    private function revokeDependentDelegations(Actor $actor, RoleAssignment $assignment, CarbonImmutable $now, string $today): void
    {
        $delegations = Delegation::query()
            ->where('source_role_assignment_id', $assignment->id)
            ->whereNull('revoked_at')
            ->where('effective_until', '>', $today)
            ->lockForUpdate()
            ->get();

        foreach ($delegations as $delegation) {
            $this->lock->update($delegation, $delegation->lock_version, [
                'revoked_at' => $now,
                'revoked_by_user_id' => $actor->userId,
                'revoke_reason' => 'Role sumber delegasi dicabut.',
            ]);

            $this->audit->record(new AuditEntry(
                action: 'delegation.revoked',
                entityType: 'delegation',
                entityId: $delegation->public_id,
                before: ['revoked_at' => null],
                after: ['revoked_at' => $now->toIso8601String(), 'cause' => 'source_role_revoked'],
                reason: 'Role sumber delegasi dicabut.',
                context: $actor->audit,
            ));
        }
    }

    /**
     * @return string|null the unit code for organization-scoped roles
     */
    private function assertScope(Role $role, ?int $unitId, string $effectiveFrom): ?string
    {
        if ($role->scopeKind() !== ScopeKind::Organization) {
            if ($unitId !== null) {
                throw new BusinessRuleViolation('invalid_scope', "Role {$role->label()} tidak memakai scope organisasi.");
            }

            return null;
        }

        $unit = $unitId === null ? null : $this->directory->snapshot($effectiveFrom)->unit($unitId);

        if ($unit === null || ! in_array($unit->type, $role->allowedUnitTypes(), true)) {
            $allowed = implode(' atau ', array_map(static fn ($type): string => $type->label(), $role->allowedUnitTypes()));

            throw new BusinessRuleViolation('invalid_scope', "Role {$role->label()} harus memakai scope {$allowed} yang aktif pada tanggal mulai.");
        }

        return $unit->code;
    }
}
