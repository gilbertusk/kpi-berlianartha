<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\AccessDecision;
use App\Modules\IdentityAccess\Application\AccountService;
use App\Modules\IdentityAccess\Application\RoleAssignmentService;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\RoleCapabilityMatrix;
use App\Modules\Organization\Application\EmployeeService;
use App\Modules\Organization\Application\OrganizationStructureService;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Shared\Application\Audit\Actor;
use Carbon\CarbonImmutable;
use Tests\Support\AccessFixtures;

/*
 * Authorization matrix for Step 05: every role is checked for allow AND deny,
 * across same team / other team / other department, active / expired /
 * future / revoked assignments, deactivated accounts, and self-approval.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();

    $this->access = app(AccessControl::class);

    $this->salesStaffUser = AccessFixtures::user('Staff Sales');
    $this->salesStaff = AccessFixtures::employee($this->salesStaffUser, 'COMSUP-SALES');
    AccessFixtures::grant($this->salesStaffUser, Role::Employee);

    $this->salesColleague = AccessFixtures::employee(null, 'COMSUP-SALES');
    $this->designA = AccessFixtures::employee(null, 'COMSUP-DESIGN-A');
    $this->arStaff = AccessFixtures::employee(null, 'ACC-AR');
    $this->wovenStaff = AccessFixtures::employee(null, 'PROD-WOVEN');

    $this->tlSalesUser = AccessFixtures::user('TL Sales');
    $this->tlSales = AccessFixtures::employee($this->tlSalesUser, 'COMSUP-SALES');
    // Granted earlier in the year so historical checks have a grant to find.
    AccessFixtures::on('2026-01-01', fn () => AccessFixtures::grant($this->tlSalesUser, Role::TeamLeader, 'COMSUP-SALES', '2026-01-01'));

    $this->users = [
        'management' => AccessFixtures::userWithRole(Role::Management, 'BAL'),
        'department_head' => AccessFixtures::userWithRole(Role::DepartmentHead, 'COMSUP'),
        'team_leader' => $this->tlSalesUser,
        'employee' => $this->salesStaffUser,
        'hr_performance' => AccessFixtures::userWithRole(Role::HrPerformance, 'BAL'),
        'auditor' => AccessFixtures::userWithRole(Role::Auditor, 'BAL'),
        'system_admin' => AccessFixtures::userWithRole(Role::SystemAdmin),
    ];
});

function canSee(User $user, Employee $employee): bool
{
    $access = app(AccessControl::class);

    return $access->allows($user, Capability::EmployeeView, $access->employeeTarget($employee));
}

test('every role holds exactly the capabilities of the matrix, no more and no less', function (string $roleKey): void {
    $matrix = app(RoleCapabilityMatrix::class);
    $role = Role::from($roleKey);

    foreach (Capability::cases() as $capability) {
        expect($this->access->allows($this->users[$roleKey], $capability))
            ->toBe($matrix->allows($role, $capability), "{$roleKey} / {$capability->value}");
    }
})->with(['management', 'department_head', 'team_leader', 'employee', 'hr_performance', 'auditor', 'system_admin']);

test('employee data is visible only inside each role scope', function (string $roleKey, array $visible): void {
    $targets = [
        'self' => $this->salesStaff,
        'same_team' => $this->salesColleague,
        'other_team_same_department' => $this->designA,
        'other_department' => $this->arStaff,
        'production' => $this->wovenStaff,
    ];

    foreach ($targets as $name => $employee) {
        expect(canSee($this->users[$roleKey], $employee))->toBe(in_array($name, $visible, true), "{$roleKey} → {$name}");
    }
})->with([
    'management (company)' => ['management', ['self', 'same_team', 'other_team_same_department', 'other_department', 'production']],
    'department head (Commercial dan Support)' => ['department_head', ['self', 'same_team', 'other_team_same_department']],
    'team leader (Sales)' => ['team_leader', ['self', 'same_team']],
    'employee (self only)' => ['employee', ['self']],
    'HR performance (company)' => ['hr_performance', ['self', 'same_team', 'other_team_same_department', 'other_department', 'production']],
    'auditor (company)' => ['auditor', ['self', 'same_team', 'other_team_same_department', 'other_department', 'production']],
    'system admin (no employee data)' => ['system_admin', []],
]);

test('department-scoped HR and auditors stay inside their department', function (): void {
    $hrAccounting = AccessFixtures::userWithRole(Role::HrPerformance, 'ACC');
    $auditorProduction = AccessFixtures::userWithRole(Role::Auditor, 'PROD');

    expect(canSee($hrAccounting, $this->arStaff))->toBeTrue()
        ->and(canSee($hrAccounting, $this->salesStaff))->toBeFalse()
        ->and($this->access->allows($hrAccounting, Capability::EmployeeManage, $this->access->employeeTarget($this->arStaff)))->toBeTrue()
        ->and($this->access->allows($hrAccounting, Capability::EmployeeManage, $this->access->employeeTarget($this->wovenStaff)))->toBeFalse()
        ->and(canSee($auditorProduction, $this->wovenStaff))->toBeTrue()
        ->and(canSee($auditorProduction, $this->arStaff))->toBeFalse();
});

test('a team leader assigned as Manager of a single team does not see sibling teams', function (): void {
    $manager = AccessFixtures::userWithRole(Role::DepartmentHead, 'COMSUP-DESIGN-A');

    expect(canSee($manager, $this->designA))->toBeTrue()
        ->and(canSee($manager, $this->salesColleague))->toBeFalse();
});

test('expired and future assignments grant nothing today but history stays reconstructable', function (): void {
    $expired = AccessFixtures::user('TL lama');
    AccessFixtures::on('2026-01-01', fn () => AccessFixtures::grant($expired, Role::TeamLeader, 'COMSUP-SALES', '2026-01-01', '2026-10-01'));
    $future = AccessFixtures::user('TL baru');
    AccessFixtures::grant($future, Role::TeamLeader, 'COMSUP-SALES', '2026-11-01');

    $target = $this->access->employeeTarget($this->salesColleague);
    $september = CarbonImmutable::parse('2026-09-15 01:00:00', 'UTC');

    expect($this->access->decide($expired, Capability::EmployeeView, $target)->denialReason)->toBe(AccessDecision::NO_GRANT)
        ->and($this->access->allows($expired, Capability::EmployeeView, $target, $september))->toBeTrue()
        ->and($this->access->decide($future, Capability::EmployeeView, $target)->denialReason)->toBe(AccessDecision::NO_GRANT);
});

test('revocation ends access immediately without rewriting earlier access', function (): void {
    $assignment = AccessFixtures::grant($leader = AccessFixtures::user('TL dicabut'), Role::TeamLeader, 'COMSUP-SALES');
    $beforeRevocation = CarbonImmutable::now()->subMinute();

    app(RoleAssignmentService::class)->revoke(Actor::system('test'), $assignment->id, $assignment->lock_version, 'Pindah tugas');

    expect(canSee($leader, $this->salesColleague))->toBeFalse()
        ->and($this->access->allows($leader, Capability::EmployeeView, $this->access->employeeTarget($this->salesColleague), $beforeRevocation))->toBeTrue();
});

test('a deactivated account loses every capability', function (): void {
    $hr = $this->users['hr_performance'];

    app(AccountService::class)->deactivate(Actor::system('test'), $hr->id, $hr->lock_version, 'Resign');
    $hr->refresh();

    foreach (Capability::cases() as $capability) {
        expect($this->access->decide($hr, $capability)->denialReason)->toBe(AccessDecision::ACCOUNT_INACTIVE);
    }
});

test('a team leader loses access to an employee who moved to another department, but only from the transfer date', function (): void {
    app(EmployeeService::class)->transferHome(
        Actor::system('test'),
        $this->salesColleague->id,
        $this->salesColleague->lock_version,
        AccessFixtures::unit('ACC-AR')->id,
        '2026-10-12',
        null,
        'Mutasi',
    );

    $leader = $this->users['team_leader'];
    $yesterday = CarbonImmutable::parse('2026-10-11 01:00:00', 'UTC');

    expect(canSee($leader, $this->salesColleague->fresh()))->toBeFalse()
        ->and($this->access->allows($leader, Capability::EmployeeView, $this->access->employeeTarget($this->salesColleague, $yesterday), $yesterday))->toBeTrue();
});

test('nobody may approve their own record, even with company-wide authority', function (): void {
    $hrUser = $this->users['hr_performance'];
    $hrEmployee = AccessFixtures::employee($hrUser, 'COMSUP-HR');

    expect($this->access->decide($hrUser, Capability::ApprovalDecide, $this->access->employeeTarget($hrEmployee))->denialReason)
        ->toBe(AccessDecision::SELF_ACTION)
        ->and($this->access->allows($hrUser, Capability::ApprovalDecide, $this->access->employeeTarget($this->salesColleague)))->toBeTrue()
        ->and($this->access->decide($this->tlSalesUser, Capability::ApprovalDecide, $this->access->employeeTarget($this->tlSales))->denialReason)
        ->toBe(AccessDecision::SELF_ACTION)
        ->and($this->access->allows($this->tlSalesUser, Capability::ApprovalDecide, $this->access->employeeTarget($this->salesColleague)))->toBeTrue();
});

test('system admin and auditor cannot modify or approve KPI values anywhere', function (string $roleKey): void {
    $user = $this->users[$roleKey];

    foreach ([$this->salesColleague, $this->arStaff, $this->wovenStaff] as $employee) {
        $target = $this->access->employeeTarget($employee);

        expect($this->access->allows($user, Capability::KpiValueModify, $target))->toBeFalse()
            ->and($this->access->allows($user, Capability::ApprovalDecide, $target))->toBeFalse();
    }
})->with(['system_admin', 'auditor']);

test('auditor is read-only: every mutating capability is denied', function (): void {
    foreach (Capability::cases() as $capability) {
        if (! $capability->isReadOnly()) {
            expect($this->access->allows($this->users['auditor'], $capability))->toBeFalse($capability->value);
        }
    }
});

test('a grant on a closed unit covers nothing', function (): void {
    $hotCutLeader = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-HOT-CUT');
    $hotCut = AccessFixtures::unit('PROD-HOT-CUT');

    app(OrganizationStructureService::class)->closeUnit(Actor::system('test'), $hotCut->id, $hotCut->lock_version, '2026-10-12', 'Digabung');
    $this->access->flush();

    expect($this->access->allows($hotCutLeader, Capability::OrganizationView))->toBeFalse();
});

test('list scopes match single-record decisions', function (): void {
    $scope = $this->access->unitScope($this->users['department_head'], Capability::EmployeeView);

    expect($scope->all)->toBeFalse()
        ->and($scope->coversUnit(AccessFixtures::unit('COMSUP-SALES')->id))->toBeTrue()
        ->and($scope->coversUnit(AccessFixtures::unit('COMSUP-DESIGN-B')->id))->toBeTrue()
        ->and($scope->coversUnit(AccessFixtures::unit('ACC-AR')->id))->toBeFalse()
        ->and($this->access->unitScope($this->users['employee'], Capability::EmployeeView)->reachesOthers())->toBeFalse()
        ->and($this->access->unitScope($this->users['management'], Capability::EmployeeView)->all)->toBeTrue();
});
