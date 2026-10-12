<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\RoleCapabilityMatrix;
use App\Modules\IdentityAccess\Domain\ScopeKind;
use App\Modules\Organization\Domain\UnitType;

test('all seven PRD roles exist', function (): void {
    expect(array_map(fn (Role $role): string => $role->value, Role::cases()))->toBe([
        'management',
        'department_head',
        'team_leader',
        'employee',
        'hr_performance',
        'auditor',
        'system_admin',
    ]);
});

test('auditor holds only read-only capabilities', function (): void {
    $capabilities = (new RoleCapabilityMatrix)->capabilitiesFor(Role::Auditor);

    expect($capabilities)->not->toBeEmpty();

    foreach ($capabilities as $capability) {
        expect($capability->isReadOnly())->toBeTrue("Auditor holds [{$capability->value}]");
    }
});

test('system admin and auditor can never change KPI values or approvals', function (Role $role): void {
    $matrix = new RoleCapabilityMatrix;

    expect($matrix->allows($role, Capability::KpiValueModify))->toBeFalse()
        ->and($matrix->allows($role, Capability::ApprovalDecide))->toBeFalse();
})->with([Role::SystemAdmin, Role::Auditor]);

test('system admin manages accounts and role plumbing but no organization or employee master data', function (): void {
    $matrix = new RoleCapabilityMatrix;

    expect($matrix->allows(Role::SystemAdmin, Capability::AccountManage))->toBeTrue()
        ->and($matrix->allows(Role::SystemAdmin, Capability::RoleManage))->toBeTrue()
        ->and($matrix->allows(Role::SystemAdmin, Capability::OrganizationManage))->toBeFalse()
        ->and($matrix->allows(Role::SystemAdmin, Capability::EmployeeManage))->toBeFalse()
        ->and($matrix->allows(Role::SystemAdmin, Capability::EmployeeView))->toBeFalse();
});

test('only System Admin manages accounts and roles; only HR manages organization and employees', function (Capability $capability, array $holders): void {
    $matrix = new RoleCapabilityMatrix;

    foreach (Role::cases() as $role) {
        expect($matrix->allows($role, $capability))->toBe(in_array($role, $holders, true), "{$role->value} / {$capability->value}");
    }
})->with([
    'account.manage' => [Capability::AccountManage, [Role::SystemAdmin]],
    'role.manage' => [Capability::RoleManage, [Role::SystemAdmin]],
    'organization.manage' => [Capability::OrganizationManage, [Role::HrPerformance]],
    'employee.manage' => [Capability::EmployeeManage, [Role::HrPerformance]],
    'delegation.manage' => [Capability::DelegationManage, [Role::HrPerformance]],
]);

test('delegation transfers read and approval authority only', function (): void {
    $matrix = new RoleCapabilityMatrix;

    expect($matrix->delegableCapabilitiesFor(Role::HrPerformance))
        ->not->toContain(Capability::OrganizationManage)
        ->not->toContain(Capability::EmployeeManage)
        ->not->toContain(Capability::DelegationManage)
        ->not->toContain(Capability::KpiValueModify)
        ->toContain(Capability::ApprovalDecide)
        ->and($matrix->delegableCapabilitiesFor(Role::Auditor))->toBe([])
        ->and($matrix->delegableCapabilitiesFor(Role::SystemAdmin))->toBe([])
        ->and($matrix->delegableCapabilitiesFor(Role::Employee))->toBe([]);
});

test('role scope rules separate roles from organization scope', function (): void {
    expect(Role::Employee->scopeKind())->toBe(ScopeKind::Self)
        ->and(Role::SystemAdmin->scopeKind())->toBe(ScopeKind::System)
        ->and(Role::TeamLeader->allowedUnitTypes())->toBe([UnitType::Team])
        ->and(Role::Management->allowedUnitTypes())->toBe([UnitType::Company])
        ->and(Role::DepartmentHead->allowedUnitTypes())->toBe([UnitType::Department, UnitType::Team]);
});
