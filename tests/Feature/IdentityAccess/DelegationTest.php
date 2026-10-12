<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\AccessDecision;
use App\Modules\IdentityAccess\Application\AccountService;
use App\Modules\IdentityAccess\Application\DelegationService;
use App\Modules\IdentityAccess\Application\RoleAssignmentService;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Carbon\CarbonImmutable;
use Tests\Support\AccessFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();

    $this->access = app(AccessControl::class);
    $this->delegations = app(DelegationService::class);

    $this->head = AccessFixtures::user('Head Produksi');
    $this->headAssignment = AccessFixtures::grant($this->head, Role::DepartmentHead, 'PROD');

    $this->delegateUser = AccessFixtures::user('Penerima delegasi');
    $this->delegateEmployee = AccessFixtures::employee($this->delegateUser, 'PROD-WOVEN');

    $this->wovenColleague = AccessFixtures::employee(null, 'PROD-WOVEN');
    $this->qcStaff = AccessFixtures::employee(null, 'PROD-QC');
});

function delegateWoven(object $test, string $from = '2026-10-12', string $until = '2026-10-26')
{
    return $test->delegations->create(
        Actor::system('test'),
        $test->headAssignment->id,
        $test->delegateUser->id,
        AccessFixtures::unit('PROD-WOVEN')->id,
        $from,
        $until,
        'Cuti tahunan',
    );
}

test('a delegation records giver, receiver, scope, reason, period, authorizer, and audit', function (): void {
    $delegation = delegateWoven($this);

    expect($delegation->delegator_user_id)->toBe($this->head->id)
        ->and($delegation->delegate_user_id)->toBe($this->delegateUser->id)
        ->and($delegation->role)->toBe(Role::DepartmentHead)
        ->and($delegation->organization_unit_id)->toBe(AccessFixtures::unit('PROD-WOVEN')->id)
        ->and($delegation->effective_from)->toBe('2026-10-12')
        ->and($delegation->effective_until)->toBe('2026-10-26')
        ->and($delegation->reason)->toBe('Cuti tahunan')
        ->and(AuditLog::query()->where('action', 'delegation.created')->where('entity_id', $delegation->public_id)->exists())->toBeTrue();
});

test('the delegate gains read and approval authority only inside the delegated scope', function (): void {
    delegateWoven($this);
    $this->access->flush();

    $colleague = $this->access->employeeTarget($this->wovenColleague);
    $qc = $this->access->employeeTarget($this->qcStaff);

    expect($this->access->allows($this->delegateUser, Capability::EmployeeView, $colleague))->toBeTrue()
        ->and($this->access->allows($this->delegateUser, Capability::ApprovalDecide, $colleague))->toBeTrue()
        ->and($this->access->allows($this->delegateUser, Capability::EmployeeView, $qc))->toBeFalse()
        ->and($this->access->allows($this->delegateUser, Capability::KpiValueModify, $colleague))->toBeFalse();
});

test('a delegation never opens self-approval for the delegate', function (): void {
    delegateWoven($this);
    $this->access->flush();

    expect($this->access->decide($this->delegateUser, Capability::ApprovalDecide, $this->access->employeeTarget($this->delegateEmployee))->denialReason)
        ->toBe(AccessDecision::SELF_ACTION);
});

test('a delegate cannot approve the delegator own record', function (): void {
    $headEmployee = AccessFixtures::employee($this->head, 'PROD-WOVEN');

    delegateWoven($this);
    $this->access->flush();

    expect($this->access->decide($this->delegateUser, Capability::ApprovalDecide, $this->access->employeeTarget($headEmployee))->denialReason)
        ->toBe(AccessDecision::SELF_ACTION)
        ->and($this->access->allows($this->delegateUser, Capability::EmployeeView, $this->access->employeeTarget($headEmployee)))->toBeTrue();
});

test('HR delegation transfers no master-data management rights', function (): void {
    $hr = AccessFixtures::user('HR');
    $hrAssignment = AccessFixtures::grant($hr, Role::HrPerformance, 'BAL');

    $this->delegations->create(Actor::system('test'), $hrAssignment->id, $this->delegateUser->id, AccessFixtures::unit('PROD')->id, '2026-10-12', '2026-10-20', 'Cuti');
    $this->access->flush();

    expect($this->access->allows($this->delegateUser, Capability::EmployeeView, $this->access->employeeTarget($this->qcStaff)))->toBeTrue()
        ->and($this->access->allows($this->delegateUser, Capability::EmployeeManage))->toBeFalse()
        ->and($this->access->allows($this->delegateUser, Capability::OrganizationManage))->toBeFalse()
        ->and($this->access->allows($this->delegateUser, Capability::DelegationManage))->toBeFalse();
});

test('a delegation expires automatically at its end date and the expiry is audited once', function (): void {
    $delegation = delegateWoven($this, '2026-10-12', '2026-10-14');
    $colleague = fn () => $this->access->employeeTarget($this->wovenColleague);

    $this->access->flush();
    expect($this->access->allows($this->delegateUser, Capability::EmployeeView, $colleague()))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-10-13 18:00:00', 'UTC')); // 01:00 on 14 Oct in Jakarta
    $this->access->flush();

    expect($this->access->allows($this->delegateUser, Capability::EmployeeView, $colleague()))->toBeFalse()
        ->and($delegation->fresh()?->statusAt('2026-10-14', CarbonImmutable::now()))->toBe('expired');

    $this->artisan('identity:expire-delegations')->assertSuccessful();
    $this->artisan('identity:expire-delegations')->assertSuccessful();

    expect(AuditLog::query()->where('action', 'delegation.expired')->where('entity_id', $delegation->public_id)->count())->toBe(1)
        ->and($delegation->fresh()?->expiry_recorded_at)->not->toBeNull();
});

test('revoking a delegation ends it immediately', function (): void {
    $delegation = delegateWoven($this);

    $this->delegations->revoke(Actor::system('test'), $delegation->id, $delegation->lock_version, 'Kembali dari cuti');

    expect($this->access->allows($this->delegateUser, Capability::EmployeeView, $this->access->employeeTarget($this->wovenColleague)))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'delegation.revoked')->exists())->toBeTrue();
});

test('a delegation lapses when the giver loses the source role or the account', function (): void {
    $delegation = delegateWoven($this);

    app(RoleAssignmentService::class)->revoke(Actor::system('test'), $this->headAssignment->id, $this->headAssignment->lock_version, 'Mutasi');

    expect($this->access->allows($this->delegateUser, Capability::EmployeeView, $this->access->employeeTarget($this->wovenColleague)))->toBeFalse()
        ->and($delegation->fresh()?->revoked_at)->not->toBeNull()
        ->and($delegation->fresh()?->revoke_reason)->toBe('Role sumber delegasi dicabut.');
});

test('a delegation lapses when the giver account is deactivated', function (): void {
    delegateWoven($this);

    app(AccountService::class)->deactivate(Actor::system('test'), $this->head->id, $this->head->lock_version, 'Resign');

    expect($this->access->allows($this->delegateUser, Capability::EmployeeView, $this->access->employeeTarget($this->wovenColleague)))->toBeFalse();
});

test('delegation rules reject unsafe requests', function (string $expectedRule, Closure $operation): void {
    expect(AccessFixtures::violatedRule(fn () => $operation->call($this)))->toBe($expectedRule);
})->with([
    'to the role holder' => ['self_delegation', function () {
        return $this->delegations->create(Actor::system('test'), $this->headAssignment->id, $this->head->id, AccessFixtures::unit('PROD-WOVEN')->id, '2026-10-12', '2026-10-20', 'x');
    }],
    'to the authorizing account itself' => ['self_grant', function () {
        $actor = new Actor($this->delegateUser->id, Actor::system('test')->audit);

        return $this->delegations->create($actor, $this->headAssignment->id, $this->delegateUser->id, AccessFixtures::unit('PROD-WOVEN')->id, '2026-10-12', '2026-10-20', 'x');
    }],
    'outside the giver scope' => ['scope_exceeded', function () {
        return $this->delegations->create(Actor::system('test'), $this->headAssignment->id, $this->delegateUser->id, AccessFixtures::unit('ACC-AR')->id, '2026-10-12', '2026-10-20', 'x');
    }],
    'starting in the past' => ['retroactive_delegation', function () {
        return delegateWoven($this, '2026-10-01', '2026-10-20');
    }],
    'without a valid end date' => ['invalid_period', function () {
        return delegateWoven($this, '2026-10-20', '2026-10-20');
    }],
    'longer than the source role' => ['exceeds_source_period', function () {
        $limited = AccessFixtures::grant(AccessFixtures::user('TL kontrak'), Role::TeamLeader, 'PROD-QC', '2026-10-12', '2026-10-20');

        return $this->delegations->create(Actor::system('test'), $limited->id, $this->delegateUser->id, AccessFixtures::unit('PROD-QC')->id, '2026-10-12', '2026-10-25', 'x');
    }],
    'for a non-delegable role' => ['role_not_delegable', function () {
        $auditor = AccessFixtures::grant(AccessFixtures::user('Auditor'), Role::Auditor, 'BAL');

        return $this->delegations->create(Actor::system('test'), $auditor->id, $this->delegateUser->id, AccessFixtures::unit('PROD')->id, '2026-10-12', '2026-10-20', 'x');
    }],
    'to an inactive account' => ['account_inactive', function () {
        app(AccountService::class)->deactivate(Actor::system('test'), $this->delegateUser->id, $this->delegateUser->lock_version, 'Keluar');

        return delegateWoven($this);
    }],
    'duplicating an existing delegation' => ['delegation_overlap', function () {
        delegateWoven($this);

        return delegateWoven($this, '2026-10-20', '2026-10-30');
    }],
]);
