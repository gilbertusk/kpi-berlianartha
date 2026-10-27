<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Models\RoleAssignment;
use Database\Seeders\StagingPilotSeeder;
use Illuminate\Support\Facades\Config;

test('staging pilot seed refuses local, testing, and production environments', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);
    Config::set('identity.staging_seed_confirm', 'staging');
    Config::set('identity.staging_admin_email', 'owner@example.org');

    expect(fn () => $this->seed(StagingPilotSeeder::class))->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
})->with(['local', 'testing', 'production']);

test('staging pilot seed needs an explicit confirmation and valid external admin email', function (): void {
    app()->detectEnvironment(fn (): string => 'staging');
    Config::set('identity.staging_admin_email', 'owner@example.org');

    expect(fn () => $this->seed(StagingPilotSeeder::class))->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);

    Config::set('identity.staging_seed_confirm', 'staging');
    Config::set('identity.staging_admin_email', 'owner@kpi.example.test');
    expect(fn () => $this->seed(StagingPilotSeeder::class))->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
});

test('staging pilot seed creates scoped fictitious users and only the configured owner as system admin', function (): void {
    app()->detectEnvironment(fn (): string => 'staging');
    Config::set('identity.staging_seed_confirm', 'staging');
    Config::set('identity.staging_admin_email', 'owner@example.org');

    $this->seed(StagingPilotSeeder::class);
    $firstCount = User::query()->count();
    $firstGrants = RoleAssignment::query()->count();

    $admin = User::query()->where('email', 'owner@example.org')->firstOrFail();
    expect(RoleAssignment::query()->where('user_id', $admin->id)->pluck('role')->all())
        ->toBe([Role::SystemAdmin]);
    expect(User::query()->where('email', 'staging.staff.sales@kpi.example.test')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'staging.staff.ar@kpi.example.test')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'staging.staff.woven@kpi.example.test')->exists())->toBeTrue();

    $this->seed(StagingPilotSeeder::class);
    expect(User::query()->count())->toBe($firstCount)
        ->and(RoleAssignment::query()->count())->toBe($firstGrants);
});

test('staging pilot seed refuses an existing unrelated owner account', function (): void {
    app()->detectEnvironment(fn (): string => 'staging');
    Config::set('identity.staging_seed_confirm', 'staging');
    Config::set('identity.staging_admin_email', 'owner@example.org');
    User::factory()->create(['email' => 'owner@example.org', 'name' => 'Existing owner']);

    expect(fn () => $this->seed(StagingPilotSeeder::class))->toThrow(RuntimeException::class);
    expect(RoleAssignment::query()->count())->toBe(0);
});
