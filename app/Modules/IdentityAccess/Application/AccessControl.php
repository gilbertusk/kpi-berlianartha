<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\RoleCapabilityMatrix;
use App\Modules\IdentityAccess\Domain\ScopeKind;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\Delegation;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\OrganizationSnapshot;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Shared\Application\Time\BusinessTime;
use Carbon\CarbonImmutable;

/**
 * Single authorization decision point (ADR-0009). Policies, Livewire actions,
 * and list queries all ask this service, so role, organization scope,
 * effective dates, revocation, delegation, and the no-self-approval rule are
 * evaluated the same way everywhere.
 *
 * Results are memoized per account and business date for the current request.
 * Use cases that change grants call {@see flush()}.
 */
final class AccessControl
{
    /** @var array<string, GrantSet> */
    private array $memo = [];

    public function __construct(
        private readonly RoleCapabilityMatrix $matrix,
        private readonly OrganizationDirectory $directory,
        private readonly BusinessTime $time,
    ) {}

    public function flush(): void
    {
        $this->memo = [];
        $this->directory->flush();
    }

    public function grants(User $user, ?CarbonImmutable $at = null): GrantSet
    {
        $instant = $at ?? CarbonImmutable::now();
        $businessDate = $this->time->businessDate($instant);

        if (! $user->isActive()) {
            return new GrantSet($businessDate, []);
        }

        $key = $at === null ? "{$user->id}|now|{$businessDate}" : "{$user->id}|{$instant->format('Y-m-d\TH:i:s.u')}";

        return $this->memo[$key] ??= $this->loadGrants($user, $businessDate, $instant);
    }

    public function decide(User $user, Capability $capability, ?AccessTarget $target = null, ?CarbonImmutable $at = null): AccessDecision
    {
        if (! $user->isActive()) {
            return AccessDecision::deny(AccessDecision::ACCOUNT_INACTIVE);
        }

        $grantSet = $this->grants($user, $at);
        $candidates = $grantSet->withCapability($capability);

        if ($candidates === []) {
            return AccessDecision::deny(AccessDecision::NO_GRANT);
        }

        if ($target === null) {
            return AccessDecision::allow($candidates[0]);
        }

        // No self-approval: neither directly nor through a delegation received.
        if ($target->isEmployee() && $capability->requiresIndependence() && $target->employeeUserId === $user->id) {
            return AccessDecision::deny(AccessDecision::SELF_ACTION);
        }

        $snapshot = $this->directory->snapshot($grantSet->businessDate);
        $skippedForIndependence = false;

        foreach ($candidates as $grant) {
            if ($target->isEmployee() && $capability->requiresIndependence() && $grant->actsForUser($target->employeeUserId)) {
                $skippedForIndependence = true;

                continue;
            }

            $covered = $target->isEmployee()
                ? $grant->coversEmployee($user->id, $target->employeeUserId, $target->unitIds, $snapshot)
                : $target->unitId !== null && $grant->coversUnit($target->unitId, $snapshot);

            if ($covered) {
                return AccessDecision::allow($grant);
            }
        }

        return AccessDecision::deny($skippedForIndependence ? AccessDecision::SELF_ACTION : AccessDecision::OUT_OF_SCOPE);
    }

    public function allows(User $user, Capability $capability, ?AccessTarget $target = null, ?CarbonImmutable $at = null): bool
    {
        return $this->decide($user, $capability, $target, $at)->allowed;
    }

    /**
     * @param  list<Capability>  $capabilities
     */
    public function allowsAny(User $user, array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->allows($user, $capability)) {
                return true;
            }
        }

        return false;
    }

    public function unitScope(User $user, Capability $capability, ?CarbonImmutable $at = null): UnitScope
    {
        $grantSet = $this->grants($user, $at);
        $grants = $grantSet->withCapability($capability);

        if ($grants === []) {
            return UnitScope::none();
        }

        $snapshot = $this->directory->snapshot($grantSet->businessDate);
        $all = false;
        $self = false;
        $unitIds = [];

        foreach ($grants as $grant) {
            if ($grant->companyWide) {
                $all = true;
            } elseif ($grant->scopeKind === ScopeKind::Self) {
                $self = true;
            } elseif ($grant->unitId !== null) {
                $unitIds = [...$unitIds, ...$snapshot->subtreeIds($grant->unitId)];
            }
        }

        return new UnitScope($all, array_values(array_unique($unitIds)), $self);
    }

    public function unitTarget(int $unitId): AccessTarget
    {
        return AccessTarget::unit($unitId);
    }

    public function employeeTarget(Employee $employee, ?CarbonImmutable $at = null): AccessTarget
    {
        $businessDate = $this->time->businessDate($at ?? CarbonImmutable::now());

        return AccessTarget::employee(
            $employee->id,
            $employee->user_id,
            $this->directory->employeeUnitIds($employee->id, $businessDate),
        );
    }

    public function businessToday(): string
    {
        return $this->time->businessDate(CarbonImmutable::now());
    }

    private function loadGrants(User $user, string $businessDate, CarbonImmutable $at): GrantSet
    {
        $snapshot = $this->directory->snapshot($businessDate);
        $grants = [];

        $assignments = RoleAssignment::query()
            ->where('user_id', $user->id)
            ->activeAt($businessDate, $at)
            ->orderBy('id')
            ->get();

        foreach ($assignments as $assignment) {
            $grant = $this->assignmentGrant($assignment, $snapshot);

            if ($grant !== null) {
                $grants[] = $grant;
            }
        }

        $delegations = Delegation::query()
            ->where('delegate_user_id', $user->id)
            ->activeAt($businessDate, $at)
            ->with(['sourceAssignment', 'delegator'])
            ->orderBy('id')
            ->get();

        foreach ($delegations as $delegation) {
            $grant = $this->delegationGrant($delegation, $snapshot, $businessDate, $at);

            if ($grant !== null) {
                $grants[] = $grant;
            }
        }

        return new GrantSet($businessDate, $grants);
    }

    private function assignmentGrant(RoleAssignment $assignment, OrganizationSnapshot $snapshot): ?Grant
    {
        $role = $assignment->role;
        $scopeKind = $role->scopeKind();
        $unit = $assignment->organization_unit_id === null ? null : $snapshot->unit($assignment->organization_unit_id);

        // A grant on a unit that is closed (or not yet open) on this date
        // covers nothing; failing closed is safer than guessing a successor.
        if ($scopeKind === ScopeKind::Organization && $unit === null) {
            return null;
        }

        return new Grant(
            role: $role,
            scopeKind: $scopeKind,
            unitId: $unit?->id,
            unitCode: $unit?->code,
            companyWide: $scopeKind === ScopeKind::System || $unit?->type === UnitType::Company,
            capabilities: $this->matrix->capabilitiesFor($role),
            source: Grant::SOURCE_ASSIGNMENT,
            sourcePublicId: $assignment->public_id,
            effectiveUntil: $assignment->effective_until,
        );
    }

    private function delegationGrant(Delegation $delegation, OrganizationSnapshot $snapshot, string $businessDate, CarbonImmutable $at): ?Grant
    {
        $source = $delegation->sourceAssignment;

        // A delegation can never outlive or outgrow the delegator's own grant.
        if (! $delegation->delegator->isActive() || ! $source->isActiveAt($businessDate, $at) || $source->role !== $delegation->role) {
            return null;
        }

        $unit = $snapshot->unit($delegation->organization_unit_id);

        if ($unit === null) {
            return null;
        }

        $sourceUnit = $source->organization_unit_id === null ? null : $snapshot->unit($source->organization_unit_id);
        $sourceIsCompanyWide = $sourceUnit?->type === UnitType::Company;

        if (! $sourceIsCompanyWide && ($sourceUnit === null || ! $snapshot->contains($sourceUnit->id, $unit->id))) {
            return null;
        }

        return new Grant(
            role: $delegation->role,
            scopeKind: ScopeKind::Organization,
            unitId: $unit->id,
            unitCode: $unit->code,
            companyWide: $unit->type === UnitType::Company,
            capabilities: $this->matrix->delegableCapabilitiesFor($delegation->role),
            source: Grant::SOURCE_DELEGATION,
            sourcePublicId: $delegation->public_id,
            effectiveUntil: $delegation->effective_until,
            delegatorUserId: $delegation->delegator_user_id,
        );
    }
}
