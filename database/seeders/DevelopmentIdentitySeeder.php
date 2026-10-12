<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\IdentityAccess\Application\DelegationService;
use App\Modules\IdentityAccess\Application\RoleAssignmentService;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\Delegation;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Organization\Application\EmployeeService;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fictitious accounts for local development and manual testing. Every name
 * starts with "[DEV]", every email uses the reserved example.test domain,
 * and the password is never a production credential. Refuses to run in
 * production. Idempotent by email / employee number.
 */
final class DevelopmentIdentitySeeder extends Seeder
{
    public const EMAIL_DOMAIN = 'kpi.example.test';

    /**
     * email local part => [name, role, scope unit code|null, employee number|null, home team code|null]
     *
     * @var array<string, array{0: string, 1: Role, 2: string|null, 3: string|null, 4: string|null}>
     */
    public const ACCOUNTS = [
        'dev.management' => ['[DEV] Management', Role::Management, 'BAL', null, null],
        'dev.hr' => ['[DEV] HR Performance', Role::HrPerformance, 'BAL', 'DEV-0001', 'COMSUP-HR'],
        'dev.auditor' => ['[DEV] Auditor', Role::Auditor, 'BAL', null, null],
        'dev.sysadmin' => ['[DEV] System Admin', Role::SystemAdmin, null, null, null],
        'dev.head.comsup' => ['[DEV] Head Commercial dan Support', Role::DepartmentHead, 'COMSUP', null, null],
        'dev.head.accounting' => ['[DEV] Head Accounting', Role::DepartmentHead, 'ACC', null, null],
        'dev.head.produksi' => ['[DEV] Head Produksi', Role::DepartmentHead, 'PROD', null, null],
        'dev.tl.sales' => ['[DEV] Team Leader Sales', Role::TeamLeader, 'COMSUP-SALES', 'DEV-0101', 'COMSUP-SALES'],
        'dev.tl.ar' => ['[DEV] Team Leader Account Receivable', Role::TeamLeader, 'ACC-AR', 'DEV-0201', 'ACC-AR'],
        'dev.tl.woven' => ['[DEV] Team Leader Woven', Role::TeamLeader, 'PROD-WOVEN', 'DEV-0301', 'PROD-WOVEN'],
        'dev.staff.sales' => ['[DEV] Staff Sales', Role::Employee, null, 'DEV-0102', 'COMSUP-SALES'],
        'dev.staff.ar' => ['[DEV] Staff Account Receivable', Role::Employee, null, 'DEV-0202', 'ACC-AR'],
        'dev.staff.woven' => ['[DEV] Staff Woven', Role::Employee, null, 'DEV-0302', 'PROD-WOVEN'],
    ];

    public function run(
        EmployeeService $employees,
        RoleAssignmentService $roles,
        DelegationService $delegations,
        BusinessTime $time,
    ): void {
        if (App::isProduction()) {
            throw new RuntimeException('DevelopmentIdentitySeeder must never run in production.');
        }

        $configuredPassword = Config::get('identity.development_seed_password');
        $password = is_string($configuredPassword) && $configuredPassword !== '' ? $configuredPassword : Str::password(20, symbols: false);
        $effectiveFrom = OrganizationReferenceSeeder::effectiveFrom();
        // Role grants cannot start in the past; employees may.
        $grantFrom = max($effectiveFrom, $time->businessDate(CarbonImmutable::now()));
        $actor = Actor::system('seeder', self::class);
        $reason = '[DEV] Data uji fiktif';
        $created = [];

        foreach (self::ACCOUNTS as $localPart => [$name, $role, $scopeCode, $employeeNumber, $homeTeamCode]) {
            $email = "{$localPart}@".self::EMAIL_DOMAIN;
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'email_verified_at' => CarbonImmutable::now(),
                ])->save();
                $created[] = $email;
            }

            if ($employeeNumber !== null && ! Employee::query()->where('employee_number', $employeeNumber)->exists()) {
                $employee = $employees->register($actor, $employeeNumber, $name, $this->unitId($homeTeamCode), $effectiveFrom, null, $reason);
                $employee->forceFill(['user_id' => $user->id])->save();
            }

            $this->grantOnce($roles, $actor, $user, $role, $scopeCode, $grantFrom, $reason);

            if ($employeeNumber !== null && $role !== Role::Employee) {
                $this->grantOnce($roles, $actor, $user, Role::Employee, null, $grantFrom, $reason);
            }
        }

        $this->seedExampleDelegation($delegations, $actor, $time);

        $this->command->table(['Email', 'Status'], array_map(
            static fn (string $localPart): array => ["{$localPart}@".self::EMAIL_DOMAIN, in_array("{$localPart}@".self::EMAIL_DOMAIN, $created, true) ? 'dibuat' : 'sudah ada'],
            array_keys(self::ACCOUNTS),
        ));

        if ($created !== [] && ! (is_string($configuredPassword) && $configuredPassword !== '')) {
            $this->command->warn("Password akun [DEV] baru (hanya untuk lokal, tidak disimpan): {$password}");
        }
    }

    private function grantOnce(RoleAssignmentService $roles, Actor $actor, User $user, Role $role, ?string $scopeCode, string $effectiveFrom, string $reason): void
    {
        $unitId = $this->unitId($scopeCode);

        $exists = RoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('role', $role->value)
            ->where('organization_unit_id', $unitId)
            ->whereNull('revoked_at')
            ->exists();

        if (! $exists) {
            $roles->grant($actor, $user->id, $role, $unitId, $effectiveFrom, null, $reason);
        }
    }

    /**
     * Example: the Produksi head delegates review of Woven to the Woven
     * Team Leader for two weeks.
     */
    private function seedExampleDelegation(DelegationService $delegations, Actor $actor, BusinessTime $time): void
    {
        $head = User::query()->where('email', 'dev.head.produksi@'.self::EMAIL_DOMAIN)->firstOrFail();
        $delegate = User::query()->where('email', 'dev.tl.woven@'.self::EMAIL_DOMAIN)->firstOrFail();

        if (Delegation::query()->where('delegator_user_id', $head->id)->where('delegate_user_id', $delegate->id)->exists()) {
            return;
        }

        $source = RoleAssignment::query()
            ->where('user_id', $head->id)
            ->where('role', Role::DepartmentHead->value)
            ->whereNull('revoked_at')
            ->firstOrFail();

        $today = $time->businessDate(CarbonImmutable::now());
        $from = max($today, $source->effective_from);
        $until = CarbonImmutable::createFromFormat('!Y-m-d', $from)?->addDays(14)->format('Y-m-d') ?? $from;
        $woven = $this->unitId('PROD-WOVEN');

        if ($woven !== null) {
            $delegations->create($actor, $source->id, $delegate->id, $woven, $from, $until, '[DEV] Contoh delegasi selama cuti');
        }
    }

    private function unitId(?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        $id = OrganizationUnit::query()->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("Reference unit [{$code}] is missing. Run OrganizationReferenceSeeder first.");
        }

        return (int) $id;
    }
}
