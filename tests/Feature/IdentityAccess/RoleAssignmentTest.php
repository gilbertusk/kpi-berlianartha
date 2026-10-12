<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Application\AccountService;
use App\Modules\IdentityAccess\Application\RoleAssignmentService;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Organization\Application\OrganizationStructureService;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Tests\Support\AccessFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();

    $this->roles = app(RoleAssignmentService::class);
    $this->admin = AccessFixtures::userWithRole(Role::SystemAdmin);
    $this->adminActor = new Actor($this->admin->id, Actor::system('test')->audit);
});

test('a role grant is audited with its scope, period, and granting account', function (): void {
    $user = AccessFixtures::user();

    $assignment = $this->roles->grant($this->adminActor, $user->id, Role::TeamLeader, AccessFixtures::unit('PROD-WOVEN')->id, '2026-10-12', null, 'Penunjukan TL');

    $audit = AuditLog::query()->where('action', 'role.granted')->where('entity_id', $assignment->public_id)->firstOrFail();

    expect($assignment->granted_by_user_id)->toBe($this->admin->id)
        ->and($audit->after_values)->toMatchArray(['role' => 'team_leader', 'scope' => 'PROD-WOVEN', 'effective_from' => '2026-10-12'])
        ->and($audit->reason)->toBe('Penunjukan TL');
});

test('role grants enforce scope, self, and lifecycle rules', function (string $expectedRule, Closure $operation): void {
    expect(AccessFixtures::violatedRule(fn () => $operation->call($this)))->toBe($expectedRule);
})->with([
    'granting to oneself' => ['self_grant', fn () => $this->roles->grant($this->adminActor, $this->admin->id, Role::Management, AccessFixtures::unit('BAL')->id, '2026-10-12', null, 'x')],
    'team leader on a department' => ['invalid_scope', fn () => $this->roles->grant($this->adminActor, AccessFixtures::user()->id, Role::TeamLeader, AccessFixtures::unit('PROD')->id, '2026-10-12', null, 'x')],
    'management on a team' => ['invalid_scope', fn () => $this->roles->grant($this->adminActor, AccessFixtures::user()->id, Role::Management, AccessFixtures::unit('PROD-QC')->id, '2026-10-12', null, 'x')],
    'organization role without scope' => ['invalid_scope', fn () => $this->roles->grant($this->adminActor, AccessFixtures::user()->id, Role::Auditor, null, '2026-10-12', null, 'x')],
    'system admin bound to a unit' => ['invalid_scope', fn () => $this->roles->grant($this->adminActor, AccessFixtures::user()->id, Role::SystemAdmin, AccessFixtures::unit('BAL')->id, '2026-10-12', null, 'x')],
    'employee role without employee record' => ['employee_link_required', fn () => $this->roles->grant($this->adminActor, AccessFixtures::user()->id, Role::Employee, null, '2026-10-12', null, 'x')],
    'scope not active on start date' => ['invalid_scope', function () {
        $hotCut = AccessFixtures::unit('PROD-HOT-CUT');
        app(OrganizationStructureService::class)->closeUnit(Actor::system('test'), $hotCut->id, $hotCut->lock_version, '2026-11-01', 'Digabung');

        return $this->roles->grant($this->adminActor, AccessFixtures::user()->id, Role::TeamLeader, $hotCut->id, '2026-11-02', null, 'x');
    }],
    'starting in the past' => ['retroactive_change', fn () => $this->roles->grant($this->adminActor, AccessFixtures::user()->id, Role::TeamLeader, AccessFixtures::unit('PROD-QC')->id, '2026-10-01', null, 'x')],
    'end before start' => ['invalid_period', fn () => $this->roles->grant($this->adminActor, AccessFixtures::user()->id, Role::TeamLeader, AccessFixtures::unit('PROD-QC')->id, '2026-10-12', '2026-10-01', 'x')],
    'missing reason' => ['reason_required', fn () => $this->roles->grant($this->adminActor, AccessFixtures::user()->id, Role::TeamLeader, AccessFixtures::unit('PROD-QC')->id, '2026-10-12', null, '  ')],
    'overlapping duplicate' => ['assignment_overlap', function () {
        $user = AccessFixtures::user();
        $this->roles->grant($this->adminActor, $user->id, Role::TeamLeader, AccessFixtures::unit('PROD-QC')->id, '2026-10-12', null, 'x');

        return $this->roles->grant($this->adminActor, $user->id, Role::TeamLeader, AccessFixtures::unit('PROD-QC')->id, '2026-11-01', null, 'x');
    }],
    'inactive account' => ['account_inactive', function () {
        $user = AccessFixtures::user();
        app(AccountService::class)->deactivate($this->adminActor, $user->id, $user->lock_version, 'Keluar');

        return $this->roles->grant($this->adminActor, $user->id, Role::TeamLeader, AccessFixtures::unit('PROD-QC')->id, '2026-10-12', null, 'x');
    }],
    'revoking own role' => ['self_grant', function () {
        $own = $this->admin->roleAssignments()->firstOrFail();

        return $this->roles->revoke($this->adminActor, $own->id, $own->lock_version, 'x');
    }],
]);

test('an administrator cannot give business roles to an account they provisioned', function (): void {
    $puppet = app(AccountService::class)->provision($this->adminActor, 'Akun boneka', 'boneka@fixtures.example.test', null)->user;

    expect(AccessFixtures::violatedRule(fn () => $this->roles->grant($this->adminActor, $puppet->id, Role::HrPerformance, AccessFixtures::unit('BAL')->id, '2026-10-12', null, 'x')))
        ->toBe('provisioner_grant');

    $secondAdmin = AccessFixtures::userWithRole(Role::SystemAdmin);
    $assignment = $this->roles->grant(new Actor($secondAdmin->id, Actor::system('test')->audit), $puppet->id, Role::HrPerformance, AccessFixtures::unit('BAL')->id, '2026-10-12', null, 'Disetujui admin kedua');

    expect($assignment->granted_by_user_id)->toBe($secondAdmin->id);
});

test('the same person can hold one role in two scopes', function (): void {
    $user = AccessFixtures::user();

    $this->roles->grant($this->adminActor, $user->id, Role::TeamLeader, AccessFixtures::unit('PROD-QC')->id, '2026-10-12', null, 'TL QC');
    $this->roles->grant($this->adminActor, $user->id, Role::TeamLeader, AccessFixtures::unit('PROD-WOVEN')->id, '2026-10-12', null, 'TL Woven');

    expect($user->roleAssignments()->count())->toBe(2);
});

test('a revoked role can be granted again later', function (): void {
    $user = AccessFixtures::user();
    $first = $this->roles->grant($this->adminActor, $user->id, Role::TeamLeader, AccessFixtures::unit('PROD-QC')->id, '2026-10-12', null, 'x');
    $this->roles->revoke($this->adminActor, $first->id, $first->lock_version, 'Salah input');

    $second = $this->roles->grant($this->adminActor, $user->id, Role::TeamLeader, AccessFixtures::unit('PROD-QC')->id, '2026-10-12', null, 'Koreksi');

    expect($second->id)->not->toBe($first->id)
        ->and(AuditLog::query()->where('action', 'role.revoked')->where('entity_id', $first->public_id)->exists())->toBeTrue();
});
