<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\Delegation;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Organization\Infrastructure\Persistence\Models\EmployeeAssignment;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;

/*
 * Direct URL and forged Livewire action tests: hiding a button is never the
 * control; every page and every action authorizes on the server.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();

    $this->salesStaffUser = AccessFixtures::user('Staff Sales');
    $this->salesStaff = AccessFixtures::employee($this->salesStaffUser, 'COMSUP-SALES');
    AccessFixtures::grant($this->salesStaffUser, Role::Employee);

    $this->salesColleague = AccessFixtures::employee(null, 'COMSUP-SALES');
    $this->arStaff = AccessFixtures::employee(null, 'ACC-AR');

    $this->users = [
        'management' => AccessFixtures::userWithRole(Role::Management, 'BAL'),
        'department_head' => AccessFixtures::userWithRole(Role::DepartmentHead, 'COMSUP'),
        'team_leader' => AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-SALES'),
        'employee' => $this->salesStaffUser,
        'hr_performance' => AccessFixtures::userWithRole(Role::HrPerformance, 'BAL'),
        'auditor' => AccessFixtures::userWithRole(Role::Auditor, 'BAL'),
        'system_admin' => AccessFixtures::userWithRole(Role::SystemAdmin),
        'no_role' => AccessFixtures::user('Tanpa role'),
    ];
});

dataset('pages', [
    'administration.index' => ['administration.index', ['management', 'department_head', 'team_leader', 'hr_performance', 'auditor', 'system_admin']],
    'administration.organization' => ['administration.organization', ['management', 'department_head', 'team_leader', 'hr_performance', 'auditor', 'system_admin']],
    'administration.employees' => ['administration.employees', ['management', 'department_head', 'team_leader', 'hr_performance', 'auditor']],
    'administration.accounts' => ['administration.accounts', ['auditor', 'system_admin']],
    'administration.roles' => ['administration.roles', ['management', 'hr_performance', 'auditor', 'system_admin']],
    'administration.delegations' => ['administration.delegations', ['management', 'department_head', 'team_leader', 'hr_performance', 'auditor', 'system_admin']],
    'administration.access-review' => ['administration.access-review', ['management', 'hr_performance', 'auditor', 'system_admin']],
]);

test('each administration page allows exactly the authorized roles', function (string $route, array $allowed): void {
    foreach ($this->users as $roleKey => $user) {
        $expected = in_array($roleKey, $allowed, true) ? 200 : 403;

        $this->actingAs($user)->get(route($route))->assertStatus($expected);
    }
})->with('pages');

test('the administration menu is only rendered for accounts with an administration capability', function (): void {
    $this->actingAs($this->users['no_role'])->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('administration.index'), escape: false);

    $this->actingAs($this->users['hr_performance'])->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('administration.index'), escape: false);
});

test('employee records are only reachable by direct URL inside the viewer scope', function (string $roleKey, string $target, int $status): void {
    $employee = ['self' => $this->salesStaff, 'colleague' => $this->salesColleague, 'other_department' => $this->arStaff][$target];

    $this->actingAs($this->users[$roleKey])
        ->get(route('administration.employees.show', $employee))
        ->assertStatus($status);
})->with([
    ['employee', 'self', 200],
    ['employee', 'colleague', 403],
    ['team_leader', 'colleague', 200],
    ['team_leader', 'other_department', 403],
    ['department_head', 'colleague', 200],
    ['department_head', 'other_department', 403],
    ['auditor', 'other_department', 200],
    ['system_admin', 'colleague', 403],
    ['no_role', 'colleague', 403],
]);

test('the employee directory never lists out-of-scope people', function (): void {
    $this->actingAs($this->users['team_leader'])
        ->get(route('administration.employees'))
        ->assertOk()
        ->assertSee($this->salesColleague->employee_number)
        ->assertDontSee($this->arStaff->employee_number);
});

test('forged Livewire actions are rejected for roles without the capability', function (string $roleKey, string $component, array $state, string $action, array $parameters): void {
    $resolve = fn (array $values): array => array_map(fn ($value) => $value instanceof Closure ? $value->call($this) : $value, $values);
    $state = $resolve($state);
    $parameters = $resolve($parameters);

    Livewire::actingAs($this->users[$roleKey])
        ->test($component)
        ->set($state)
        ->call($action, ...$parameters)
        ->assertForbidden();
})->with([
    'auditor grants a role' => ['auditor', 'pages::administration.roles', [
        'grantUser' => fn () => $this->users['no_role']->public_id,
        'grantRole' => 'management',
        'grantUnit' => fn () => AccessFixtures::unit('BAL')->public_id,
        'grantFrom' => '2026-10-12',
        'grantReason' => 'forged',
    ], 'grant', []],
    'management grants a role' => ['management', 'pages::administration.roles', [
        'grantUser' => fn () => $this->users['no_role']->public_id,
        'grantRole' => 'auditor',
        'grantUnit' => fn () => AccessFixtures::unit('BAL')->public_id,
        'grantFrom' => '2026-10-12',
        'grantReason' => 'forged',
    ], 'grant', []],
    'system admin creates an organization unit' => ['system_admin', 'pages::administration.organization', [
        'newType' => 'team',
        'newCode' => 'PROD-NEW',
        'newName' => 'Forged',
        'newParent' => fn () => AccessFixtures::unit('PROD')->public_id,
        'newFrom' => '2026-10-12',
        'newReason' => 'forged',
    ], 'createUnit', []],
    'team leader creates an organization unit' => ['team_leader', 'pages::administration.organization', [
        'newType' => 'team',
        'newCode' => 'COMSUP-NEW',
        'newName' => 'Forged',
        'newParent' => fn () => AccessFixtures::unit('COMSUP')->public_id,
        'newFrom' => '2026-10-12',
        'newReason' => 'forged',
    ], 'createUnit', []],
    'auditor revokes a role' => ['auditor', 'pages::administration.roles', [], 'startRevoke', [fn () => RoleAssignment::query()->firstOrFail()->public_id]],
    'auditor sends a password reset' => ['auditor', 'pages::administration.accounts', [], 'sendReset', [fn () => $this->users['no_role']->public_id]],
    'auditor deactivates an account' => ['auditor', 'pages::administration.accounts', [], 'select', [fn () => $this->users['no_role']->public_id, 'deactivate']],
    'system admin deactivates own account' => ['system_admin', 'pages::administration.accounts', [], 'select', [fn () => $this->users['system_admin']->public_id, 'deactivate']],
]);

test('a department-scoped auditor reviews only accounts and grants inside the department', function (): void {
    $auditorAccounting = AccessFixtures::userWithRole(Role::Auditor, 'ACC');
    $arLeader = AccessFixtures::user('TL AR');
    AccessFixtures::employee($arLeader, 'ACC-AR');
    AccessFixtures::grant($arLeader, Role::TeamLeader, 'ACC-AR');
    AccessFixtures::grant($arLeader, Role::Auditor, 'PROD');

    $this->actingAs($auditorAccounting)
        ->get(route('administration.access-review'))
        ->assertOk()
        ->assertSee($arLeader->email)
        ->assertSee('ACC-AR')
        ->assertDontSee('(PROD)')
        ->assertSee('+1 hak akses di luar scope Anda')
        ->assertDontSee($this->users['hr_performance']->email)
        ->assertSee('Audit trail hanya tersedia untuk reviewer dengan scope company.');
});

test('a team leader cannot open management forms on a visible employee', function (): void {
    Livewire::actingAs($this->users['team_leader'])
        ->test('pages::administration.employee-show', ['employee' => $this->salesColleague])
        ->call('openForm', 'transfer')
        ->assertForbidden();
});

test('department-scoped HR cannot move an employee into another department', function (): void {
    $hrAccounting = AccessFixtures::userWithRole(Role::HrPerformance, 'ACC');

    Livewire::actingAs($hrAccounting)
        ->test('pages::administration.employee-show', ['employee' => $this->arStaff])
        ->call('openForm', 'transfer')
        ->set('unitId', AccessFixtures::unit('PROD-WOVEN')->public_id)
        ->set('from', '2026-10-20')
        ->set('reason', 'Mutasi lintas departemen')
        ->call('transfer')
        ->assertForbidden();

    expect(EmployeeAssignment::query()->where('employee_id', $this->arStaff->id)->count())->toBe(1);
});

test('system admin cannot grant a role to itself through the UI', function (): void {
    Livewire::actingAs($this->users['system_admin'])
        ->test('pages::administration.roles')
        ->set('grantUser', $this->users['system_admin']->public_id)
        ->set('grantRole', 'management')
        ->set('grantUnit', AccessFixtures::unit('BAL')->public_id)
        ->set('grantFrom', '2026-10-12')
        ->set('grantReason', 'Eskalasi')
        ->call('grant')
        ->assertForbidden();

    expect($this->users['system_admin']->roleAssignments()->count())->toBe(1);
});

test('HR creates a unit and the audit names the authorizing role', function (): void {
    Livewire::actingAs($this->users['hr_performance'])
        ->test('pages::administration.organization')
        ->set('newType', 'team')
        ->set('newParent', AccessFixtures::unit('PROD')->public_id)
        ->set('newCode', 'prod-sample')
        ->set('newName', 'Sample Room')
        ->set('newFrom', '2026-11-01')
        ->set('newReason', 'Team baru disetujui')
        ->call('createUnit')
        ->assertHasNoErrors();

    $unit = OrganizationUnit::query()->where('code', 'PROD-SAMPLE')->firstOrFail();
    $audit = AuditLog::query()->where('action', 'organization.unit_created')->where('entity_id', $unit->public_id)->firstOrFail();

    expect($unit->effective_from)->toBe('2026-11-01')
        ->and($audit->actor_identifier)->toBe($this->users['hr_performance']->public_id)
        ->and($audit->actor_role)->toBe('hr_performance')
        ->and($audit->actor_scope)->toMatchArray(['role' => 'hr_performance', 'scope' => 'company', 'source' => 'assignment']);
});

test('HR registers and transfers an employee through the UI', function (): void {
    Livewire::actingAs($this->users['hr_performance'])
        ->test('pages::administration.employees')
        ->set('newNumber', 'BAL-9001')
        ->set('newName', 'Pegawai Baru')
        ->set('newTeam', AccessFixtures::unit('PROD-QC')->public_id)
        ->set('newFrom', '2026-10-12')
        ->set('newReason', 'Rekrutmen')
        ->call('register')
        ->assertHasNoErrors();

    $employee = Employee::query()->where('employee_number', 'BAL-9001')->firstOrFail();

    Livewire::actingAs($this->users['hr_performance'])
        ->test('pages::administration.employee-show', ['employee' => $employee])
        ->call('openForm', 'transfer')
        ->set('unitId', AccessFixtures::unit('PROD-WOVEN')->public_id)
        ->set('from', '2026-11-01')
        ->set('reason', 'Rotasi')
        ->call('transfer')
        ->assertHasNoErrors();

    expect(EmployeeAssignment::query()->where('employee_id', $employee->id)->orderBy('effective_from')->pluck('effective_until')->all())
        ->toBe(['2026-11-01', null]);
});

test('a stale form reports a concurrency conflict instead of overwriting', function (): void {
    $page = Livewire::actingAs($this->users['hr_performance'])
        ->test('pages::administration.employee-show', ['employee' => $this->salesColleague])
        ->call('openForm', 'rename');

    $this->salesColleague->forceFill(['full_name' => 'Diubah pengguna lain', 'lock_version' => 99])->save();

    $page->set('fullName', 'Nama versi lama')
        ->set('reason', 'Koreksi')
        ->call('rename')
        ->assertHasErrors('action');

    expect($this->salesColleague->fresh()?->full_name)->toBe('Diubah pengguna lain');
});

test('system admin provisions an account that receives a password setup link', function (): void {
    Notification::fake();

    Livewire::actingAs($this->users['system_admin'])
        ->test('pages::administration.accounts')
        ->set('newName', 'Akun Baru')
        ->set('newEmail', 'Akun.Baru@kpi.example.test')
        ->set('newEmployeeNumber', $this->salesColleague->employee_number)
        ->call('provision')
        ->assertHasNoErrors();

    $account = User::query()->where('email', 'akun.baru@kpi.example.test')->firstOrFail();

    Notification::assertSentTo($account, ResetPassword::class);

    expect($account->email_verified_at)->toBeNull()
        ->and($this->salesColleague->fresh()?->user_id)->toBe($account->id)
        ->and(AuditLog::query()->where('action', 'account.provisioned')->where('entity_id', $account->public_id)->exists())->toBeTrue();
});

test('a team leader delegates their own role through the UI', function (): void {
    $delegate = $this->users['no_role'];
    $source = RoleAssignment::query()->where('user_id', $this->users['team_leader']->id)->firstOrFail();

    Livewire::actingAs($this->users['team_leader'])
        ->test('pages::administration.delegations')
        ->set('source', $source->public_id)
        ->set('delegate', $delegate->public_id)
        ->set('unit', AccessFixtures::unit('COMSUP-SALES')->public_id)
        ->set('from', '2026-10-12')
        ->set('until', '2026-10-19')
        ->set('reason', 'Cuti')
        ->call('create')
        ->assertHasNoErrors();

    $delegation = Delegation::query()->firstOrFail();

    expect($delegation->delegator_user_id)->toBe($this->users['team_leader']->id)
        ->and($delegation->granted_by_user_id)->toBe($this->users['team_leader']->id);
});

test('a team leader cannot delegate another person role', function (): void {
    $otherLeader = AccessFixtures::userWithRole(Role::TeamLeader, 'ACC-AR');
    $source = RoleAssignment::query()->where('user_id', $otherLeader->id)->firstOrFail();

    Livewire::actingAs($this->users['team_leader'])
        ->test('pages::administration.delegations')
        ->set('source', $source->public_id)
        ->set('delegate', $this->users['no_role']->public_id)
        ->set('unit', AccessFixtures::unit('ACC-AR')->public_id)
        ->set('from', '2026-10-12')
        ->set('until', '2026-10-19')
        ->set('reason', 'Forged')
        ->call('create')
        ->assertForbidden();
});
