<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\IdentityAccess\Application\RoleAssignmentService;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Organization\Application\EmployeeService;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Time\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Explicit staging-only demonstration accounts. Not production master data,
 * approved pilot teams, approvers, or UAT evidence. Never called by DatabaseSeeder.
 */
final class StagingPilotSeeder extends Seeder
{
    public function run(EmployeeService $employees, RoleAssignmentService $roles, BusinessTime $time): void
    {
        if (! app()->environment('staging')) {
            throw new RuntimeException('StagingPilotSeeder requires APP_ENV=staging.');
        }

        if (Config::get('identity.staging_seed_confirm') !== 'staging') {
            throw new RuntimeException('Explicit staging seed confirmation is required.');
        }

        $email = Config::get('identity.staging_admin_email');
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false || str_ends_with(strtolower($email), '@'.DevelopmentIdentitySeeder::EMAIL_DOMAIN)) {
            throw new RuntimeException('Configure a valid external staging admin email outside the repository.');
        }

        $referenceDate = Config::get('organization.reference_effective_from');
        if (! is_string($referenceDate) || trim($referenceDate) === '') {
            throw new RuntimeException('A reviewed organization reference date is required for staging.');
        }

        $admin = User::query()->where('email', $email)->first();
        if ($admin !== null && $admin->name !== '[STAGING] System Admin') {
            throw new RuntimeException('Configured staging admin email already belongs to another account.');
        }
        if ($admin !== null && RoleAssignment::query()->where('user_id', $admin->id)
            ->where('role', '!=', Role::SystemAdmin->value)->whereNull('revoked_at')->exists()) {
            throw new RuntimeException('Configured staging admin already has a business role.');
        }

        // Fail before writing if a demo email is already owned by another user.
        foreach (DevelopmentIdentitySeeder::ACCOUNTS as $localPart => [$name, $role]) {
            if ($role === Role::SystemAdmin) {
                continue;
            }

            $demoEmail = $this->demoEmail($localPart);
            $existing = User::query()->where('email', $demoEmail)->first();
            if ($existing !== null && $existing->name !== str_replace('[DEV]', '[STAGING]', $name)) {
                throw new RuntimeException('A staging demonstration email already belongs to another account.');
            }
        }

        $actor = Actor::system('seeder', self::class);
        $effectiveFrom = OrganizationReferenceSeeder::effectiveFrom();
        $grantFrom = max($effectiveFrom, $time->businessDate(CarbonImmutable::now()));

        DB::transaction(function () use ($employees, $roles, $actor, $effectiveFrom, $grantFrom, $email): void {
            $admin = $this->createAccount($email, '[STAGING] System Admin');
            $this->grantOnce($roles, $actor, $admin, Role::SystemAdmin, null, $grantFrom);

            foreach (DevelopmentIdentitySeeder::ACCOUNTS as $localPart => [$name, $role, $scopeCode, $employeeNumber, $homeTeamCode]) {
                if ($role === Role::SystemAdmin) {
                    continue;
                }

                $user = $this->createAccount($this->demoEmail($localPart), str_replace('[DEV]', '[STAGING]', $name));
                if ($employeeNumber !== null) {
                    $number = str_replace('DEV-', 'STG-', $employeeNumber);
                    $employee = Employee::query()->where('employee_number', $number)->first();
                    if ($employee === null) {
                        $employee = $employees->register($actor, $number, $user->name, $this->unitId($homeTeamCode), $effectiveFrom, null, '[STAGING] Data demonstrasi');
                        $employee->forceFill(['user_id' => $user->id])->save();
                    } elseif ($employee->user_id !== $user->id) {
                        throw new RuntimeException('A staging employee number belongs to another account.');
                    }
                }

                $this->grantOnce($roles, $actor, $user, $role, $scopeCode, $grantFrom);
                if ($employeeNumber !== null && $role !== Role::Employee) {
                    $this->grantOnce($roles, $actor, $user, Role::Employee, null, $grantFrom);
                }
            }
        });

        $this->command->info('Staging demonstration accounts prepared. No passwords or email addresses were printed.');
    }

    private function demoEmail(string $localPart): string
    {
        return str_replace('dev.', 'staging.', $localPart).'@'.DevelopmentIdentitySeeder::EMAIL_DOMAIN;
    }

    private function createAccount(string $email, string $name): User
    {
        $user = User::query()->where('email', $email)->first();
        if ($user !== null) {
            return $user;
        }

        return User::query()->create([
            'name' => $name,
            'email' => $email,
            // Never share a predictable seed password. Reset via approved safe
            // staging mail after IT has configured delivery and MFA policy.
            'password' => Str::password(48),
        ]);
    }

    private function grantOnce(RoleAssignmentService $roles, Actor $actor, User $user, Role $role, ?string $scopeCode, string $effectiveFrom): void
    {
        $unitId = $this->unitId($scopeCode);
        if (RoleAssignment::query()->where('user_id', $user->id)->where('role', $role->value)
            ->where('organization_unit_id', $unitId)->whereNull('revoked_at')->exists()) {
            return;
        }

        $roles->grant($actor, $user->id, $role, $unitId, $effectiveFrom, null, '[STAGING] Hak demonstrasi, bukan approver bisnis resmi');
    }

    private function unitId(?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        $id = OrganizationUnit::query()->where('code', $code)->value('id');
        if ($id === null) {
            throw new RuntimeException('Missing reference unit; seed the approved organization structure first.');
        }

        return (int) $id;
    }
}
