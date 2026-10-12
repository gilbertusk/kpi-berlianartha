<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\RoleAssignmentService;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Organization\Application\EmployeeService;
use App\Modules\Organization\Application\OrganizationStructureService;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\OrganizationReferenceSeeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * Builds the PRD reference structure and accounts with scoped roles through
 * the real application services, so tests exercise the same rules as the UI.
 */
final class AccessFixtures
{
    /** Business date used by authorization tests (Asia/Jakarta). */
    public const TODAY = '2026-10-12';

    public const STRUCTURE_FROM = '2026-01-01';

    private static int $sequence = 0;

    /**
     * 08:00 Asia/Jakarta on {@see TODAY}.
     */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TODAY.' 01:00:00', 'UTC');
    }

    public static function seedStructure(): void
    {
        Config::set('organization.reference_effective_from', self::STRUCTURE_FROM);
        app(OrganizationReferenceSeeder::class)->run(app(OrganizationStructureService::class));
    }

    public static function unit(string $code): OrganizationUnit
    {
        return OrganizationUnit::query()->where('code', $code)->firstOrFail();
    }

    public static function user(string $label = 'Pengguna uji'): User
    {
        self::$sequence++;

        return User::factory()->create([
            'name' => $label.' '.self::$sequence,
            'email' => 'fixture'.self::$sequence.'.'.uniqid().'@fixtures.example.test',
        ]);
    }

    public static function grant(User $user, Role $role, ?string $unitCode = null, ?string $from = null, ?string $until = null): RoleAssignment
    {
        return app(RoleAssignmentService::class)->grant(
            Actor::system('test'),
            $user->id,
            $role,
            $unitCode === null ? null : self::unit($unitCode)->id,
            $from ?? self::TODAY,
            $until,
            'Fixture',
        );
    }

    /**
     * Runs setup "in the past" (business date at 08:00 Asia/Jakarta), for
     * history that the services refuse to create retroactively, then returns
     * to {@see now()}.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function on(string $businessDate, Closure $operation): mixed
    {
        Date::setTestNow(CarbonImmutable::parse($businessDate.' 01:00:00', 'UTC'));
        app(AccessControl::class)->flush();

        try {
            return $operation();
        } finally {
            Date::setTestNow(self::now());
            app(AccessControl::class)->flush();
        }
    }

    public static function userWithRole(Role $role, ?string $unitCode = null, ?string $from = null, ?string $until = null): User
    {
        $user = self::user($role->label());

        if ($role === Role::Employee) {
            self::employee($user, 'COMSUP-SALES');
        }

        self::grant($user, $role, $unitCode, $from, $until);

        return $user;
    }

    public static function employee(?User $user, string $teamCode, ?string $from = null, ?string $number = null): Employee
    {
        self::$sequence++;

        $employee = app(EmployeeService::class)->register(
            Actor::system('test'),
            $number ?? 'T-'.str_pad((string) self::$sequence, 5, '0', STR_PAD_LEFT),
            $user?->name ?? 'Pegawai uji '.self::$sequence,
            self::unit($teamCode)->id,
            $from ?? self::STRUCTURE_FROM,
            null,
            'Fixture',
        );

        if ($user !== null) {
            $employee->forceFill(['user_id' => $user->id])->save();
        }

        return $employee->refresh();
    }

    /**
     * Runs an operation that must break a domain rule and returns the rule key.
     */
    public static function violatedRule(callable $operation): string
    {
        try {
            $operation();
        } catch (BusinessRuleViolation $violation) {
            return $violation->rule;
        }

        throw new RuntimeException('Expected a BusinessRuleViolation.');
    }
}
