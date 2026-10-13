<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ApprovalStageBypass;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Registers the signed permission to skip the review stage for one team.
 * The system never bypasses a stage on its own: a bypass exists only as a
 * record that names its sign-off, and it applies only to scorecards whose
 * snapshot shows no reviewer.
 */
final readonly class BypassService
{
    use GuardsInput;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OptimisticLock $lock,
        private AccessControl $access,
    ) {}

    public function register(Actor $actor, User $user, int $unitId, string $signoffReference, string $reason, string $effectiveFrom, ?string $effectiveUntil): ApprovalStageBypass
    {
        $signoffReference = self::requireText($signoffReference, 'signoff_reference', 'Referensi sign-off wajib diisi.', 255);
        $reason = self::requireText($reason, 'reason', 'Alasan bypass wajib diisi.');
        $effectiveUntil = $effectiveUntil === null || trim($effectiveUntil) === '' ? null : trim($effectiveUntil);
        $period = self::requirePeriod(trim($effectiveFrom), $effectiveUntil);

        self::requireNotBefore($period->effectiveFrom, $this->access->businessToday());

        return $this->transactions->run(function () use ($actor, $user, $unitId, $signoffReference, $reason, $period): ApprovalStageBypass {
            $unit = OrganizationUnit::query()->lockForUpdate()->findOrFail($unitId);
            $this->assertMay($user, $unit->id);

            if ($unit->unit_type !== UnitType::Team) {
                throw new BusinessRuleViolation('bypass_team_only', 'Bypass tahap review hanya dapat dicatat untuk sebuah team.');
            }

            $overlapping = ApprovalStageBypass::query()
                ->where('organization_unit_id', $unit->id)
                ->where('stage', ApprovalStage::Review->value)
                ->whereNull('revoked_at')
                ->where(fn (Builder $until) => $until->whereNull('effective_until')->orWhere('effective_until', '>', $period->effectiveFrom))
                ->when($period->effectiveUntil !== null, fn (Builder $from) => $from->where('effective_from', '<', $period->effectiveUntil))
                ->exists();

            if ($overlapping) {
                throw new BusinessRuleViolation('bypass_overlap', 'Team ini sudah memiliki bypass yang berlaku pada rentang tanggal tersebut.');
            }

            $bypass = ApprovalStageBypass::query()->create([
                'organization_unit_id' => $unit->id,
                'stage' => ApprovalStage::Review->value,
                'signoff_reference' => $signoffReference,
                'reason' => $reason,
                'effective_from' => $period->effectiveFrom,
                'effective_until' => $period->effectiveUntil,
                'created_by_user_id' => $user->id,
            ]);

            $this->audit->record(new AuditEntry(
                action: 'approval_bypass.registered',
                entityType: 'approval_stage_bypass',
                entityId: $bypass->public_id,
                before: null,
                after: ['unit' => $unit->code, 'stage' => $bypass->stage, 'signoff_reference' => $signoffReference, 'effective_from' => $period->effectiveFrom, 'effective_until' => $period->effectiveUntil],
                reason: $reason,
                context: $actor->audit,
            ));

            return $bypass;
        });
    }

    public function revoke(Actor $actor, User $user, int $bypassId, int $expectedVersion, string $reason): ApprovalStageBypass
    {
        $reason = self::requireText($reason, 'reason', 'Alasan pencabutan wajib diisi.');

        return $this->transactions->run(function () use ($actor, $user, $bypassId, $expectedVersion, $reason): ApprovalStageBypass {
            $bypass = ApprovalStageBypass::query()->with('unit')->lockForUpdate()->findOrFail($bypassId);
            $this->assertMay($user, $bypass->organization_unit_id);

            if ($bypass->revoked_at !== null) {
                throw new BusinessRuleViolation('bypass_revoked', 'Bypass ini sudah dicabut.');
            }

            $bypass = $this->lock->update($bypass, $expectedVersion, [
                'revoked_at' => CarbonImmutable::now(),
                'revoked_by_user_id' => $user->id,
                'revoke_reason' => $reason,
            ]);

            $this->audit->record(new AuditEntry(
                action: 'approval_bypass.revoked',
                entityType: 'approval_stage_bypass',
                entityId: $bypass->public_id,
                before: ['revoked' => false],
                after: ['revoked' => true, 'unit' => $bypass->unit->code],
                reason: $reason,
                context: $actor->audit,
            ));

            return $bypass;
        });
    }

    private function assertMay(User $user, int $unitId): void
    {
        if (! $this->access->allows($user, Capability::ApprovalRouteManage, $this->access->unitTarget($unitId))) {
            throw new AuthorizationException;
        }
    }
}
