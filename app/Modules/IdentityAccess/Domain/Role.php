<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain;

use App\Modules\Organization\Domain\UnitType;

/**
 * Minimum roles from PRD §5. A role never implies organization scope; scope
 * comes from the assignment (ROLE_APPROVAL_MATRIX.md).
 */
enum Role: string
{
    case Management = 'management';
    case DepartmentHead = 'department_head';
    case TeamLeader = 'team_leader';
    case Employee = 'employee';
    case HrPerformance = 'hr_performance';
    case Auditor = 'auditor';
    case SystemAdmin = 'system_admin';

    public function label(): string
    {
        return match ($this) {
            self::Management => 'Management',
            self::DepartmentHead => 'Department Head/Manager',
            self::TeamLeader => 'Team Leader',
            self::Employee => 'Employee',
            self::HrPerformance => 'HR Performance',
            self::Auditor => 'Auditor',
            self::SystemAdmin => 'System Admin',
        };
    }

    public function scopeKind(): ScopeKind
    {
        return match ($this) {
            self::Employee => ScopeKind::Self,
            self::SystemAdmin => ScopeKind::System,
            default => ScopeKind::Organization,
        };
    }

    /**
     * Organization unit types a role assignment may be scoped to. Empty for
     * roles that are not organization-scoped.
     *
     * @return list<UnitType>
     */
    public function allowedUnitTypes(): array
    {
        return match ($this) {
            self::Management => [UnitType::Company],
            self::DepartmentHead => [UnitType::Department, UnitType::Team],
            self::TeamLeader => [UnitType::Team],
            self::HrPerformance => [UnitType::Company, UnitType::Department],
            self::Auditor => [UnitType::Company, UnitType::Department, UnitType::Team],
            self::Employee, self::SystemAdmin => [],
        };
    }

    /**
     * Approval-chain roles may be delegated for a bounded period (PRD FR 017).
     * Self-service, audit, and technical roles cannot be delegated.
     */
    public function isDelegable(): bool
    {
        return match ($this) {
            self::Management, self::DepartmentHead, self::TeamLeader, self::HrPerformance => true,
            self::Employee, self::Auditor, self::SystemAdmin => false,
        };
    }
}
