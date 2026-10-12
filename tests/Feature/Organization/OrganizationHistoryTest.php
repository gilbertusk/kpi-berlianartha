<?php

declare(strict_types=1);

use App\Modules\Organization\Application\EmployeeService;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\OrganizationStructureService;
use App\Modules\Organization\Domain\AssignmentType;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\EmployeeAssignment;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Domain\Exceptions\ConcurrencyConflict;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Tests\Support\AccessFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
});

test('transferring an employee keeps the earlier assignment and its history unchanged', function (): void {
    $employee = AccessFixtures::employee(null, 'PROD-WOVEN', '2026-01-01');
    $directory = app(OrganizationDirectory::class);
    $woven = AccessFixtures::unit('PROD-WOVEN');
    $flexo = AccessFixtures::unit('PROD-FLEXO');

    app(EmployeeService::class)->transferHome(Actor::system('test'), $employee->id, $employee->lock_version, $flexo->id, '2026-10-15', 'Operator', 'Rotasi lini');

    $assignments = EmployeeAssignment::query()->where('employee_id', $employee->id)->orderBy('effective_from')->get();

    expect($assignments)->toHaveCount(2)
        ->and($assignments[0]->organization_unit_id)->toBe($woven->id)
        ->and($assignments[0]->effective_from)->toBe('2026-01-01')
        ->and($assignments[0]->effective_until)->toBe('2026-10-15')
        ->and($assignments[1]->organization_unit_id)->toBe($flexo->id)
        ->and($assignments[1]->effective_until)->toBeNull()
        ->and($directory->homeUnitId($employee->id, '2026-06-30'))->toBe($woven->id)
        ->and($directory->homeUnitId($employee->id, '2026-10-14'))->toBe($woven->id)
        ->and($directory->homeUnitId($employee->id, '2026-10-15'))->toBe($flexo->id)
        ->and(AuditLog::query()->where('action', 'employee.assignment_ended')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'employee.assignment_opened')->count())->toBe(2);
});

test('an employee never has two overlapping home teams', function (): void {
    $employee = AccessFixtures::employee(null, 'PROD-WOVEN', '2026-10-20');

    expect(AccessFixtures::violatedRule(fn () => app(EmployeeService::class)->transferHome(
        Actor::system('test'), $employee->id, $employee->lock_version, AccessFixtures::unit('PROD-QC')->id, '2026-10-20', null, 'Tanggal sama',
    )))->toBe('assignment_overlap');

    expect(AccessFixtures::violatedRule(fn () => app(EmployeeService::class)->transferHome(
        Actor::system('test'), $employee->id, $employee->lock_version, AccessFixtures::unit('PROD-QC')->id, '2026-10-15', null, 'Sebelum mulai',
    )))->toBe('assignment_overlap');
});

test('changes to existing history cannot take effect before today', function (): void {
    $employee = AccessFixtures::employee(null, 'PROD-WOVEN', '2026-01-01');
    $employees = app(EmployeeService::class);
    $structure = app(OrganizationStructureService::class);
    $open = EmployeeAssignment::query()->where('employee_id', $employee->id)->firstOrFail();
    $woven = AccessFixtures::unit('PROD-WOVEN');
    $hotCut = AccessFixtures::unit('PROD-HOT-CUT');

    expect(AccessFixtures::violatedRule(fn () => $employees->transferHome(Actor::system('test'), $employee->id, $employee->lock_version, AccessFixtures::unit('PROD-QC')->id, '2026-09-01', null, 'Mundur')))
        ->toBe('retroactive_change')
        ->and(AccessFixtures::violatedRule(fn () => $employees->endAssignment(Actor::system('test'), $open->id, $open->lock_version, '2026-09-01', 'Mundur')))
        ->toBe('retroactive_change')
        ->and(AccessFixtures::violatedRule(fn () => $employees->addTemporary(Actor::system('test'), $employee->id, $employee->lock_version, AccessFixtures::unit('PROD-QC')->id, '2026-09-01', '2026-10-30', null, 'Mundur')))
        ->toBe('retroactive_change')
        ->and(AccessFixtures::violatedRule(fn () => $structure->reviseUnit(Actor::system('test'), $woven->id, $woven->lock_version, 'Woven Baru', AccessFixtures::unit('PROD')->id, '2026-09-01', 'Mundur')))
        ->toBe('retroactive_change')
        ->and(AccessFixtures::violatedRule(fn () => $structure->closeUnit(Actor::system('test'), $hotCut->id, $hotCut->lock_version, '2026-09-01', 'Mundur')))
        ->toBe('retroactive_change')
        ->and(app(OrganizationDirectory::class)->homeUnitId($employee->id, '2026-09-15'))->toBe($woven->id);
});

test('only one company can exist', function (): void {
    expect(AccessFixtures::violatedRule(fn () => app(OrganizationStructureService::class)->createUnit(Actor::system('test'), UnitType::Company, 'BAL-2', 'Company kedua', null, '2026-10-12', null)))
        ->toBe('single_company');
});

test('assignments that already ended cannot be rewritten', function (): void {
    $employee = AccessFixtures::employee(null, 'PROD-WOVEN', '2026-01-01');
    $service = app(EmployeeService::class);

    AccessFixtures::on('2026-05-01', fn () => $service->transferHome(Actor::system('test'), $employee->id, $employee->lock_version, AccessFixtures::unit('PROD-QC')->id, '2026-05-01', null, 'Pindah'));
    $ended = EmployeeAssignment::query()->where('employee_id', $employee->id)->where('organization_unit_id', AccessFixtures::unit('PROD-WOVEN')->id)->firstOrFail();

    expect(AccessFixtures::violatedRule(fn () => $service->endAssignment(Actor::system('test'), $ended->id, $ended->lock_version, '2026-04-01', 'Ubah histori')))
        ->toBe('retroactive_change')
        ->and(AccessFixtures::violatedRule(fn () => $service->endAssignment(Actor::system('test'), $ended->id, $ended->lock_version, '2026-10-20', 'Ubah histori')))
        ->toBe('not_shortening')
        ->and($ended->fresh()?->effective_until)->toBe('2026-05-01')
        ->and($ended->fresh()?->lock_version)->toBe($ended->lock_version);

    $temporary = $service->addTemporary(Actor::system('test'), $employee->id, $employee->fresh()->lock_version, AccessFixtures::unit('PROD-FLEXO')->id, '2026-10-12', '2026-11-01', null, 'Bantu Flexo');

    expect(AccessFixtures::violatedRule(fn () => $service->endAssignment(Actor::system('test'), $temporary->id, $temporary->lock_version, '2026-11-05', 'Perpanjang')))
        ->toBe('not_shortening');

    $shortened = $service->endAssignment(Actor::system('test'), $temporary->id, $temporary->lock_version, '2026-10-20', 'Selesai lebih awal');

    expect($shortened->effective_until)->toBe('2026-10-20');
});

test('a stale editor cannot transfer an employee changed by someone else', function (): void {
    $employee = AccessFixtures::employee(null, 'PROD-WOVEN', '2026-01-01');
    $staleVersion = $employee->lock_version;
    $service = app(EmployeeService::class);

    $service->transferHome(Actor::system('test'), $employee->id, $staleVersion, AccessFixtures::unit('PROD-QC')->id, '2026-10-20', null, 'Editor pertama');

    expect(fn () => $service->transferHome(Actor::system('test'), $employee->id, $staleVersion, AccessFixtures::unit('PROD-FLEXO')->id, '2026-10-25', null, 'Editor basi'))
        ->toThrow(ConcurrencyConflict::class);
});

test('temporary assignments require an end date and may overlap the home team', function (): void {
    $employee = AccessFixtures::employee(null, 'PROD-WOVEN', '2026-01-01');
    $service = app(EmployeeService::class);

    $temporary = $service->addTemporary(Actor::system('test'), $employee->id, $employee->lock_version, AccessFixtures::unit('PROD-QC')->id, '2026-10-12', '2026-10-26', null, 'Bantu QC');

    expect($temporary->assignment_type)->toBe(AssignmentType::Temporary)
        ->and(app(OrganizationDirectory::class)->employeeUnitIds($employee->id, '2026-10-13'))
        ->toEqualCanonicalizing([AccessFixtures::unit('PROD-WOVEN')->id, AccessFixtures::unit('PROD-QC')->id])
        ->and(app(OrganizationDirectory::class)->employeeUnitIds($employee->id, '2026-10-26'))
        ->toBe([AccessFixtures::unit('PROD-WOVEN')->id]);

    expect(AccessFixtures::violatedRule(fn () => $service->addTemporary(Actor::system('test'), $employee->id, $employee->fresh()->lock_version, AccessFixtures::unit('PROD-QC')->id, '2026-10-20', '2026-10-21', null, 'Duplikat')))
        ->toBe('assignment_overlap');
});

test('assignments only target teams that are active on the start date', function (): void {
    expect(AccessFixtures::violatedRule(fn () => AccessFixtures::employee(null, 'PROD', '2026-01-01')))->toBe('invalid_unit')
        ->and(AccessFixtures::violatedRule(fn () => AccessFixtures::employee(null, 'PROD-WOVEN', '2025-06-01')))->toBe('invalid_unit');
});

test('renaming or re-parenting a unit creates a new version and keeps the old structure for earlier dates', function (): void {
    $service = app(OrganizationStructureService::class);
    $woven = AccessFixtures::unit('PROD-WOVEN');
    $comsup = AccessFixtures::unit('COMSUP');

    $service->reviseUnit(Actor::system('test'), $woven->id, $woven->lock_version, 'Woven Label', $comsup->id, '2026-11-01', 'Reorganisasi');

    $before = app(OrganizationDirectory::class)->snapshot('2026-10-31')->unit($woven->id);
    $after = app(OrganizationDirectory::class)->snapshot('2026-11-01')->unit($woven->id);

    expect($before?->name)->toBe('Woven')
        ->and($before?->parentId)->toBe(AccessFixtures::unit('PROD')->id)
        ->and($after?->name)->toBe('Woven Label')
        ->and($after?->parentId)->toBe($comsup->id);
});

test('a team must sit under a department and a department under the company', function (): void {
    $service = app(OrganizationStructureService::class);

    expect(AccessFixtures::violatedRule(fn () => $service->createUnit(Actor::system('test'), UnitType::Team, 'BAD-TEAM', 'Salah induk', AccessFixtures::unit('BAL')->id, '2026-10-12', null)))
        ->toBe('invalid_parent')
        ->and(AccessFixtures::violatedRule(fn () => $service->createUnit(Actor::system('test'), UnitType::Department, 'BAD-DEPT', 'Salah induk', AccessFixtures::unit('PROD')->id, '2026-10-12', null)))
        ->toBe('invalid_parent')
        ->and(AccessFixtures::violatedRule(fn () => $service->createUnit(Actor::system('test'), UnitType::Team, 'PROD-WOVEN', 'Duplikat', AccessFixtures::unit('PROD')->id, '2026-10-12', null)))
        ->toBe('unit_code_taken');
});

test('a unit cannot be closed while it still has active teams or members', function (): void {
    $service = app(OrganizationStructureService::class);
    $prod = AccessFixtures::unit('PROD');
    $woven = AccessFixtures::unit('PROD-WOVEN');
    AccessFixtures::employee(null, 'PROD-WOVEN', '2026-01-01');

    expect(AccessFixtures::violatedRule(fn () => $service->closeUnit(Actor::system('test'), $prod->id, $prod->lock_version, '2026-12-01', 'Tutup')))
        ->toBe('unit_has_children')
        ->and(AccessFixtures::violatedRule(fn () => $service->closeUnit(Actor::system('test'), $woven->id, $woven->lock_version, '2026-12-01', 'Tutup')))
        ->toBe('unit_has_members');

    $hotCut = AccessFixtures::unit('PROD-HOT-CUT');
    $service->closeUnit(Actor::system('test'), $hotCut->id, $hotCut->lock_version, '2026-12-01', 'Digabung');

    expect(app(OrganizationDirectory::class)->snapshot('2026-11-30')->unit($hotCut->id))->not->toBeNull()
        ->and(app(OrganizationDirectory::class)->snapshot('2026-12-01')->unit($hotCut->id))->toBeNull();
});
