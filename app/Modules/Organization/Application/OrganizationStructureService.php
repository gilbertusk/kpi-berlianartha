<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\EmployeeAssignment;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnitVersion;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\EffectivePeriod;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Effective-dated changes to the company → department → team hierarchy.
 * A rename or re-parent closes the current version and opens a new one, so
 * queries for earlier dates keep returning the earlier structure. Changes to
 * existing units take effect today or later; only a new unit may start
 * earlier (for example the reference structure).
 */
final readonly class OrganizationStructureService
{
    use GuardsInput;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OptimisticLock $lock,
        private OrganizationDirectory $directory,
        private BusinessTime $time,
    ) {}

    public function createUnit(
        Actor $actor,
        UnitType $type,
        string $code,
        string $name,
        ?int $parentUnitId,
        string $effectiveFrom,
        ?string $reason,
    ): OrganizationUnit {
        $code = self::normalizeCode($code);
        $name = self::requireText($name, 'name', 'Nama unit wajib diisi.', 120);
        self::requirePeriod($effectiveFrom);

        return $this->transactions->run(function () use ($actor, $type, $code, $name, $parentUnitId, $effectiveFrom, $reason): OrganizationUnit {
            if (OrganizationUnit::query()->where('code', $code)->exists()) {
                throw new BusinessRuleViolation('unit_code_taken', 'Kode unit sudah digunakan.');
            }

            // One company only: a second company unit would act as a
            // company-wide scope for every grant bound to it.
            if ($type === UnitType::Company && OrganizationUnit::query()->where('unit_type', UnitType::Company->value)->exists()) {
                throw new BusinessRuleViolation('single_company', 'Struktur hanya boleh memiliki satu company.');
            }

            if ($parentUnitId !== null) {
                // Serializes with a concurrent closeUnit() of the parent.
                OrganizationUnit::query()->lockForUpdate()->find($parentUnitId);
                $this->directory->flush();
            }

            $this->assertValidParent($type, $parentUnitId, $effectiveFrom);

            try {
                $unit = OrganizationUnit::query()->create([
                    'code' => $code,
                    'unit_type' => $type,
                    'effective_from' => $effectiveFrom,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessRuleViolation('unit_code_taken', 'Kode unit sudah digunakan.');
            }

            OrganizationUnitVersion::query()->create([
                'organization_unit_id' => $unit->id,
                'parent_unit_id' => $parentUnitId,
                'name' => $name,
                'effective_from' => $effectiveFrom,
                'reason' => $reason,
                'created_by_user_id' => $actor->userId,
            ]);

            $this->audit->record(new AuditEntry(
                action: 'organization.unit_created',
                entityType: 'organization_unit',
                entityId: $unit->public_id,
                before: null,
                after: [
                    'code' => $code,
                    'unit_type' => $type->value,
                    'name' => $name,
                    'parent_unit_id' => $parentUnitId,
                    'effective_from' => $effectiveFrom,
                ],
                reason: $reason,
                context: $actor->audit,
            ));

            $this->directory->flush();

            return $unit;
        });
    }

    public function reviseUnit(
        Actor $actor,
        int $unitId,
        int $expectedVersion,
        string $name,
        ?int $parentUnitId,
        string $effectiveFrom,
        string $reason,
    ): OrganizationUnitVersion {
        $name = self::requireText($name, 'name', 'Nama unit wajib diisi.', 120);
        $reason = self::requireText($reason, 'reason', 'Alasan perubahan wajib diisi.');
        self::requirePeriod($effectiveFrom);
        self::requireNotBefore($effectiveFrom, $this->today());

        return $this->transactions->run(function () use ($actor, $unitId, $expectedVersion, $name, $parentUnitId, $effectiveFrom, $reason): OrganizationUnitVersion {
            $unit = $this->lock->update(OrganizationUnit::query()->findOrFail($unitId), $expectedVersion, []);

            if (! (new EffectivePeriod($unit->effective_from, $unit->effective_until))->contains($effectiveFrom)) {
                throw new BusinessRuleViolation('unit_inactive', 'Unit tidak aktif pada tanggal berlaku tersebut.');
            }

            $current = $this->latestVersion($unit);

            if ($current->effective_until !== null || $current->effective_from >= $effectiveFrom) {
                throw new BusinessRuleViolation('version_order', 'Tanggal berlaku harus setelah versi terakhir unit.');
            }

            if ($current->name === $name && $current->parent_unit_id === $parentUnitId) {
                throw new BusinessRuleViolation('no_change', 'Tidak ada perubahan nama atau induk unit.');
            }

            $this->assertValidParent($unit->unit_type, $parentUnitId, $effectiveFrom);

            $current->forceFill(['effective_until' => $effectiveFrom])->save();

            $next = OrganizationUnitVersion::query()->create([
                'organization_unit_id' => $unit->id,
                'parent_unit_id' => $parentUnitId,
                'name' => $name,
                'effective_from' => $effectiveFrom,
                'reason' => $reason,
                'created_by_user_id' => $actor->userId,
            ]);

            $this->audit->record(new AuditEntry(
                action: 'organization.unit_revised',
                entityType: 'organization_unit',
                entityId: $unit->public_id,
                before: ['name' => $current->name, 'parent_unit_id' => $current->parent_unit_id, 'effective_from' => $current->effective_from],
                after: ['name' => $name, 'parent_unit_id' => $parentUnitId, 'effective_from' => $effectiveFrom],
                reason: $reason,
                context: $actor->audit,
            ));

            $this->directory->flush();

            return $next;
        });
    }

    public function closeUnit(Actor $actor, int $unitId, int $expectedVersion, string $effectiveUntil, string $reason): OrganizationUnit
    {
        $reason = self::requireText($reason, 'reason', 'Alasan penutupan wajib diisi.');
        self::requirePeriod($effectiveUntil);
        self::requireNotBefore($effectiveUntil, $this->today());

        return $this->transactions->run(function () use ($actor, $unitId, $expectedVersion, $effectiveUntil, $reason): OrganizationUnit {
            $unit = $this->lock->update(OrganizationUnit::query()->findOrFail($unitId), $expectedVersion, []);
            $current = $this->latestVersion($unit);

            if ($unit->effective_until !== null) {
                throw new BusinessRuleViolation('unit_closed', 'Unit sudah ditutup.');
            }

            if ($effectiveUntil <= $current->effective_from) {
                throw new BusinessRuleViolation('version_order', 'Tanggal penutupan harus setelah versi terakhir unit.');
            }

            $hasActiveChildren = OrganizationUnitVersion::query()
                ->where('parent_unit_id', $unit->id)
                ->where(fn (Builder $query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $effectiveUntil))
                ->whereHas('unit', fn (Builder $child) => $child->whereNull('effective_until')->orWhere('effective_until', '>', $effectiveUntil))
                ->exists();

            if ($hasActiveChildren) {
                throw new BusinessRuleViolation('unit_has_children', 'Unit masih memiliki unit turunan yang aktif.');
            }

            $hasActiveMembers = EmployeeAssignment::query()
                ->where('organization_unit_id', $unit->id)
                ->where(fn (Builder $query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $effectiveUntil))
                ->exists();

            if ($hasActiveMembers) {
                throw new BusinessRuleViolation('unit_has_members', 'Unit masih memiliki penugasan pegawai yang aktif.');
            }

            $current->forceFill(['effective_until' => $effectiveUntil])->save();
            $unit->forceFill(['effective_until' => $effectiveUntil])->save();

            $this->audit->record(new AuditEntry(
                action: 'organization.unit_closed',
                entityType: 'organization_unit',
                entityId: $unit->public_id,
                before: ['effective_until' => null],
                after: ['effective_until' => $effectiveUntil],
                reason: $reason,
                context: $actor->audit,
            ));

            $this->directory->flush();

            return $unit;
        });
    }

    private function today(): string
    {
        return $this->time->businessDate(CarbonImmutable::now());
    }

    private function latestVersion(OrganizationUnit $unit): OrganizationUnitVersion
    {
        return OrganizationUnitVersion::query()
            ->where('organization_unit_id', $unit->id)
            ->orderByDesc('effective_from')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertValidParent(UnitType $type, ?int $parentUnitId, string $effectiveFrom): void
    {
        $requiredParent = $type->requiredParentType();

        if ($requiredParent === null) {
            if ($parentUnitId !== null) {
                throw new BusinessRuleViolation('invalid_parent', 'Company tidak boleh memiliki unit induk.');
            }

            return;
        }

        $parent = $parentUnitId === null ? null : $this->directory->snapshot($effectiveFrom)->unit($parentUnitId);

        if ($parent === null || $parent->type !== $requiredParent) {
            throw new BusinessRuleViolation(
                'invalid_parent',
                sprintf('%s harus berada di bawah %s yang aktif pada tanggal berlaku.', $type->label(), $requiredParent->label()),
            );
        }
    }

    private static function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));

        if (preg_match('/^[A-Z0-9][A-Z0-9-]{1,39}$/', $code) !== 1) {
            throw new BusinessRuleViolation('invalid_code', 'Kode unit hanya boleh berisi huruf, angka, dan tanda hubung (2–40 karakter).');
        }

        return $code;
    }
}
