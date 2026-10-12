<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccountService;
use App\Modules\IdentityAccess\Application\Authentication\CredentialAuthenticator;
use App\Modules\IdentityAccess\Domain\AccountStatus;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Database\Seeders\DevelopmentIdentitySeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Tests\Support\AccessFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    $this->accounts = app(AccountService::class);
    $this->admin = AccessFixtures::user('Admin');
    $this->adminActor = new Actor($this->admin->id, Actor::system('test')->audit);
});

test('a deactivated account cannot sign in and gets the generic error', function (): void {
    $user = AccessFixtures::user();
    $this->accounts->deactivate($this->adminActor, $user->id, $user->lock_version, 'Resign');

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();

    expect(AuditLog::query()->where('action', 'auth.login_failed')->where('entity_id', $user->public_id)->exists())->toBeTrue();
});

test('an open session ends on the next request after deactivation', function (): void {
    $user = AccessFixtures::user();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $this->accounts->deactivate($this->adminActor, $user->id, $user->lock_version, 'Akun disusupi');
    $user->refresh();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();

    expect(AuditLog::query()->where('action', 'auth.session_terminated')->where('entity_id', $user->public_id)->exists())->toBeTrue();
});

test('a login path that bypasses the credential check is still terminated for inactive accounts', function (): void {
    $user = AccessFixtures::user();
    $this->accounts->deactivate($this->adminActor, $user->id, $user->lock_version, 'Nonaktif');

    // Simulates passkey or two-factor completion, which log in without the authenticator.
    Route::middleware('web')->get('/_test/direct-login/{id}', function (int $id) {
        Auth::guard('web')->login(User::query()->findOrFail($id));

        return response('logged in');
    });

    $this->get("/_test/direct-login/{$user->id}")->assertRedirect(route('login'));

    $this->assertGuest();
});

test('deactivation revokes stored database sessions', function (): void {
    Config::set('session.driver', 'database');
    $user = AccessFixtures::user();

    DB::table('sessions')->insert([
        'id' => 'session-to-revoke',
        'user_id' => $user->id,
        'payload' => base64_encode('test'),
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->accounts->deactivate($this->adminActor, $user->id, $user->lock_version, 'Resign');

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'account.deactivated')->value('after_values'))
        ->toMatchArray(['status' => 'deactivated', 'terminated_sessions' => 1]);
});

test('the last active System Admin cannot be deactivated', function (): void {
    $onlyAdmin = AccessFixtures::user('Satu-satunya admin');
    AccessFixtures::grant($onlyAdmin, Role::SystemAdmin);

    expect(AccessFixtures::violatedRule(fn () => $this->accounts->deactivate($this->adminActor, $onlyAdmin->id, $onlyAdmin->lock_version, 'x')))
        ->toBe('last_system_admin');

    $secondAdmin = AccessFixtures::user('Admin kedua');
    AccessFixtures::grant($secondAdmin, Role::SystemAdmin);

    $this->accounts->deactivate($this->adminActor, $onlyAdmin->id, $onlyAdmin->lock_version, 'Rotasi admin');

    expect($onlyAdmin->fresh()?->isActive())->toBeFalse();
});

test('a throttled reset link is reported and not audited as sent', function (): void {
    $user = AccessFixtures::user();

    $this->accounts->sendPasswordReset($this->adminActor, $user->id);

    expect(AccessFixtures::violatedRule(fn () => $this->accounts->sendPasswordReset($this->adminActor, $user->id)))
        ->toBe('reset_link_not_sent')
        ->and(AuditLog::query()->where('action', 'account.password_reset_link_sent')->where('entity_id', $user->public_id)->count())->toBe(1);
});

test('nobody can deactivate or reactivate their own account', function (): void {
    expect(AccessFixtures::violatedRule(fn () => $this->accounts->deactivate($this->adminActor, $this->admin->id, $this->admin->lock_version, 'x')))
        ->toBe('self_account_change');
});

test('a reactivated account can sign in again', function (): void {
    $user = AccessFixtures::user();
    $this->accounts->deactivate($this->adminActor, $user->id, $user->lock_version, 'Cuti panjang');
    $user->refresh();
    $this->accounts->reactivate($this->adminActor, $user->id, $user->lock_version, 'Kembali bekerja');

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user->fresh());
});

test('successful login and logout are audited and update the last login time', function (): void {
    $user = AccessFixtures::user();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();
    $this->post(route('logout'));

    expect($user->fresh()?->last_login_at?->equalTo(AccessFixtures::now()))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'auth.login_succeeded')->where('entity_id', $user->public_id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'auth.logout')->where('entity_id', $user->public_id)->exists())->toBeTrue();
});

test('failed logins are audited without storing the attempted email or password', function (): void {
    $this->post(route('login.store'), ['email' => 'unknown.person@kpi.example.test', 'password' => 'Secret-Attempt-123'])
        ->assertSessionHasErrors('email');

    $audit = AuditLog::query()->where('action', 'auth.login_failed')->firstOrFail();
    $stored = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);

    expect($audit->entity_id)->toBe('unknown')
        ->and($audit->actor_type)->toBe('anonymous')
        ->and($stored)->not->toContain('unknown.person')
        ->and($stored)->not->toContain('Secret-Attempt-123');
});

test('completing a password reset verifies the mailbox, but not for deactivated accounts', function (): void {
    $user = User::factory()->unverified()->create();
    $token = Password::broker()->createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'New-password-123',
        'password_confirmation' => 'New-password-123',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()?->email_verified_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'auth.password_reset_completed')->where('entity_id', $user->public_id)->exists())->toBeTrue();

    $inactive = AccessFixtures::user();
    $this->accounts->deactivate($this->adminActor, $inactive->id, $inactive->lock_version, 'Resign');
    $inactiveToken = Password::broker()->createToken($inactive->fresh());

    $this->post(route('password.update'), [
        'token' => $inactiveToken,
        'email' => $inactive->email,
        'password' => 'New-password-123',
        'password_confirmation' => 'New-password-123',
    ])->assertSessionHasErrors('email');
});

test('provisioned accounts start active with an unusable password', function (): void {
    $account = $this->accounts->provision($this->adminActor, 'Pegawai Baru', 'pegawai.baru@kpi.example.test', null)->user;

    expect($account->status)->toBe(AccountStatus::Active)
        ->and(app(CredentialAuthenticator::class)->authenticate($account->email, 'password'))->toBeNull()
        ->and(AccessFixtures::violatedRule(fn () => $this->accounts->provision($this->adminActor, 'Duplikat', 'PEGAWAI.BARU@kpi.example.test', null)))
        ->toBe('email_taken');
});

test('an unknown identity provider fails loudly instead of falling back', function (): void {
    Config::set('identity.provider', 'entra-id');

    expect(fn () => app(CredentialAuthenticator::class))->toThrow(InvalidArgumentException::class, 'not implemented');
});

test('MFA enforcement is off by default and, when enabled, requires two-factor setup', function (): void {
    $user = AccessFixtures::user();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    Config::set('identity.mfa_required', true);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('security.edit'));
    $this->actingAs(User::factory()->withTwoFactor()->create())->get(route('dashboard'))->assertOk();
});

test('fictitious development accounts are clearly labelled and refused in production', function (): void {
    AccessFixtures::seedStructure();
    Config::set('identity.development_seed_password', 'local-only-password');

    $this->seed(DevelopmentIdentitySeeder::class);

    $accounts = User::query()->where('email', 'like', '%@'.DevelopmentIdentitySeeder::EMAIL_DOMAIN)->get();

    expect($accounts)->toHaveCount(count(DevelopmentIdentitySeeder::ACCOUNTS))
        ->and($accounts->every(fn (User $account): bool => str_starts_with($account->name, '[DEV] ')))->toBeTrue()
        ->and($accounts->firstWhere('email', 'dev.tl.woven@kpi.example.test')?->roleAssignments()->pluck('role')->map->value->sort()->values()->all())
        ->toBe(['employee', 'team_leader']);

    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app()->call([app(DevelopmentIdentitySeeder::class), 'run']))
        ->toThrow(RuntimeException::class, 'must never run in production');
});

test('the reference structure is the only seed outside the local environment', function (): void {
    Config::set('organization.reference_effective_from', AccessFixtures::STRUCTURE_FROM);

    $this->seed();

    expect(User::query()->where('email', 'like', '%@'.DevelopmentIdentitySeeder::EMAIL_DOMAIN)->exists())->toBeFalse()
        ->and(OrganizationUnit::query()->count())->toBe(25);
});
