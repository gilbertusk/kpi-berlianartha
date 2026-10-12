<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\ScopeKind;
use App\Modules\Organization\Application\OrganizationSnapshot;

/**
 * One effective source of authority for an account at a moment: either a
 * role assignment or a delegation received from another account.
 */
final readonly class Grant
{
    public const SOURCE_ASSIGNMENT = 'assignment';

    public const SOURCE_DELEGATION = 'delegation';

    /**
     * @param  list<Capability>  $capabilities
     */
    public function __construct(
        public Role $role,
        public ScopeKind $scopeKind,
        public ?int $unitId,
        public ?string $unitCode,
        public bool $companyWide,
        public array $capabilities,
        public string $source,
        public string $sourcePublicId,
        public ?string $effectiveUntil,
        public ?int $delegatorUserId = null,
    ) {}

    /**
     * A delegate acts on behalf of the delegator, so independence-requiring
     * actions on the delegator's own record are excluded as well.
     */
    public function actsForUser(?int $userId): bool
    {
        return $userId !== null && $this->delegatorUserId === $userId;
    }

    public function has(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function coversUnit(int $unitId, OrganizationSnapshot $snapshot): bool
    {
        if ($this->companyWide) {
            return true;
        }

        return $this->scopeKind === ScopeKind::Organization
            && $this->unitId !== null
            && $snapshot->contains($this->unitId, $unitId);
    }

    /**
     * @param  list<int>  $employeeUnitIds
     */
    public function coversEmployee(int $actorUserId, ?int $employeeUserId, array $employeeUnitIds, OrganizationSnapshot $snapshot): bool
    {
        if ($this->scopeKind === ScopeKind::Self) {
            return $employeeUserId !== null && $employeeUserId === $actorUserId;
        }

        if ($this->companyWide) {
            return true;
        }

        foreach ($employeeUnitIds as $unitId) {
            if ($this->coversUnit($unitId, $snapshot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compact, non-sensitive description stored with audit records.
     *
     * @return array<string, string|null>
     */
    public function toAuditScope(): array
    {
        return [
            'role' => $this->role->value,
            'scope' => $this->companyWide ? 'company' : ($this->unitCode ?? $this->scopeKind->value),
            'source' => $this->source,
            'source_id' => $this->sourcePublicId,
        ];
    }
}
