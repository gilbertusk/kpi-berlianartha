<?php

declare(strict_types=1);

use App\Modules\Integration\Application\EmployeeMigration;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationException;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationRun;
use App\Modules\Organization\Application\EmployeeService;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Shared\Application\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AccessFixtures;

/*
 * Employee master migration on a synthetic legacy export: two dry runs
 * that agree and write nothing, an apply, and a second apply that adds
 * nothing. Existing employees are never overwritten.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $flexo = AccessFixtures::unit('PROD-FLEXO')->id;
    app(EmployeeService::class)->register(Actor::system('test'), 'MIG-100', '[FIXTURE] Sudah Ada', $flexo, '2026-01-01', 'Operator', null);
    app(EmployeeService::class)->register(Actor::system('test'), 'MIG-101', '[FIXTURE] Nama Asli', $flexo, '2026-01-01', 'Operator', null);
    $this->fixture = base_path('tests/Fixtures/integration/legacy-employees.csv');
    $this->migration = app(EmployeeMigration::class);
});

test('two dry runs agree, write nothing, and account for every source row', function (): void {
    $before = Employee::query()->count();
    $first = $this->migration->run($this->fixture, apply: false);
    $second = $this->migration->run($this->fixture, apply: false);

    expect($first->status)->toBe(IntegrationRun::DRY_RUN)
        ->and($first->reconciliation)->toBe([
            'source_rows' => 10, 'created' => 5, 'unchanged' => 1, 'conflicts' => 1, 'rejected' => 3, 'accounted' => 10, 'balanced' => true,
            'per_team' => ['ACC-AR' => 1, 'COMSUP-SALES' => 1, 'PROD-FLEXO' => 1, 'PROD-QC' => 1, 'PROD-WOVEN' => 2],
        ])
        ->and($second->reconciliation)->toBe($first->reconciliation)
        ->and($first->reconciled)->toBeFalse()                     // conflicts and rejects need a decision
        ->and(Employee::query()->count())->toBe($before)
        ->and(IntegrationException::query()->where('integration_run_id', $first->id)->pluck('kind')->countBy()->all())->toBe(['migration_conflict' => 1, 'rows_rejected' => 3]);

    $details = IntegrationException::query()->where('integration_run_id', $first->id)->pluck('detail')->implode("\n");

    expect($details)->toContain('nomor induk duplikat dari baris 2')
        ->and($details)->toContain('team NOPE-TEAM tidak aktif')
        ->and($details)->toContain('effective_from harus tanggal YYYY-MM-DD yang valid')
        ->and($details)->toContain('MIG-101 sudah ada dengan nama atau home team berbeda; tidak ditimpa')
        ->and($details)->not->toContain('[FIXTURE]');                // no names in the register
});

test('apply creates the new employees once; a second apply adds nothing and overwrites nothing', function (): void {
    $before = Employee::query()->count();
    $apply = $this->migration->run($this->fixture, apply: true);
    $again = $this->migration->run($this->fixture, apply: true);

    expect($apply->status)->toBe(IntegrationRun::APPLIED)
        ->and($apply->reconciliation['created'] ?? null)->toBe(5)
        ->and(Employee::query()->count())->toBe($before + 5)
        ->and($again->reconciliation)->toMatchArray(['created' => 0, 'unchanged' => 6, 'conflicts' => 1, 'rejected' => 3, 'balanced' => true])
        ->and(Employee::query()->count())->toBe($before + 5)
        ->and(Employee::query()->where('employee_number', 'MIG-101')->value('full_name'))->toBe('[FIXTURE] Nama Asli')
        ->and(Employee::query()->where('employee_number', 'MIG-003')->sole()->full_name)->toBe('[FIXTURE] Pegawai Tiga');

    // Only the master moves: no KPI history, targets, actuals, or approvals are made up.
    foreach (['kpi_actuals', 'kpi_targets', 'approval_tasks', 'approval_decisions'] as $table) {
        if (Schema::hasTable($table)) {
            expect(DB::table($table)->count())->toBe(0);
        }
    }
});

test('legacy column names are mapped, and a file without the contract columns is quarantined', function (): void {
    $legacy = storage_path('framework/testing/legacy-mapped.csv');
    @mkdir(dirname($legacy), 0777, true);
    file_put_contents($legacy, "NIK;NAMA;TEAM;JABATAN;MULAI\nMIG-200;[FIXTURE] Dua Ratus;PROD-WOVEN;Operator;2026-01-01\n");
    $mapped = $this->migration->run($legacy, apply: false, columnMap: ['nik' => 'employee_number', 'nama' => 'full_name', 'team' => 'home_team_code', 'jabatan' => 'position_title', 'mulai' => 'effective_from']);
    $unmapped = $this->migration->run($legacy, apply: false);
    @unlink($legacy);

    expect($mapped->reconciliation)->toMatchArray(['created' => 1, 'balanced' => true])
        ->and($mapped->reconciled)->toBeTrue()
        ->and($unmapped->status)->toBe(IntegrationRun::QUARANTINED)
        ->and($unmapped->error_code)->toBe('invalid_file');
});

test('the command reports a dry run by default and applies only when asked', function (): void {
    $before = Employee::query()->count();

    $this->artisan('integration:migrate-employees', ['file' => $this->fixture])
        ->expectsOutputToContain('migration_dry_run')
        ->assertSuccessful();

    expect(Employee::query()->count())->toBe($before);

    $this->artisan('integration:migrate-employees', ['file' => $this->fixture, '--apply' => true])
        ->expectsOutputToContain('migration_apply')
        ->assertSuccessful();

    expect(Employee::query()->count())->toBe($before + 5);

    $this->artisan('integration:migrate-employees', ['file' => 'missing.csv'])->assertFailed();
});

test('an impossible date is a rejected row, not a failed run', function (): void {
    $file = storage_path('framework/testing/legacy-bad-date.csv');
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, "employee_number,full_name,home_team_code,position_title,effective_from\nMIG-300,[FIXTURE] Tiga Ratus,PROD-WOVEN,Operator,2026-13-45\n");
    $run = $this->migration->run($file, apply: false);
    @unlink($file);

    expect($run->status)->toBe(IntegrationRun::DRY_RUN)
        ->and($run->reconciliation)->toMatchArray(['rejected' => 1, 'balanced' => true]);
});
