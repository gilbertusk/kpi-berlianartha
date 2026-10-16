<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Integration\Application\ExceptionRegister;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationException;
use App\Modules\Integration\Infrastructure\Persistence\Models\IntegrationRun;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\IntegrationHarness;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->admin = AccessFixtures::userWithRole(Role::SystemAdmin);
    $this->hr = AccessFixtures::userWithRole(Role::HrPerformance, 'BAL');
    $this->auditor = AccessFixtures::userWithRole(Role::Auditor, 'BAL');
    $this->leader = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    $this->management = AccessFixtures::userWithRole(Role::Management, 'BAL');
    $this->exception = app(ExceptionRegister::class)->record(null, 'mes-production', 'reconciliation_difference', 'Selisih 1.000 pada quantity');
});

test('the dashboard is for System Admin, HR Performance, and the Auditor; only System Admin operates it', function (): void {
    foreach ([$this->leader, $this->management] as $outsider) {
        $this->actingAs($outsider)->get(route('integrations.index'))->assertForbidden();
    }

    $this->actingAs($this->auditor)->get(route('integrations.index'))->assertOk()
        ->assertSee('Belum ada koneksi runtime yang terbukti')
        ->assertSee('mes-production')
        ->assertSee('Koneksi belum diaktifkan.')
        ->assertSee('Selisih 1.000 pada quantity')
        ->assertDontSee('Putuskan');

    Livewire::actingAs($this->hr)->test('pages::integrations.index')
        ->call('startDecision', $this->exception->public_id)
        ->assertForbidden();

    expect(fn () => app(ExceptionRegister::class)->decide(Actor::system('test'), $this->hr, $this->exception->id, 'accepted', 'x'))->toThrow(AuthorizationException::class);

    Livewire::actingAs($this->admin)->test('pages::integrations.index')
        ->assertSee('Putuskan')
        ->call('startDecision', $this->exception->public_id)
        ->set('decision', 'accepted')
        ->set('reason', 'Diterima oleh TL Woven: pembulatan sumber')
        ->call('decide')
        ->assertHasNoErrors();

    expect($this->exception->refresh()->status)->toBe(IntegrationException::ACCEPTED)
        ->and($this->exception->resolved_by_user_id)->toBe($this->admin->id)
        ->and(AccessFixtures::violatedRule(fn () => app(ExceptionRegister::class)->decide(Actor::system('test'), $this->admin, $this->exception->id, 'resolved', 'lagi')))->toBe('exception_decided')
        ->and(fn () => DB::transaction(fn () => DB::table('integration_exceptions')->delete()))->toThrow(QueryException::class);
});

test('a dead-lettered outbox message is requeued by hand with a reason', function (): void {
    $message = OutboxMessage::query()->create([
        'event_type' => 'fixture.event', 'event_version' => 1, 'aggregate_type' => 'fixture', 'aggregate_id' => '1',
        'payload' => ['x' => 1], 'headers' => [], 'payload_hash' => hash('sha256', 'x'), 'attempts' => 5,
        'occurred_at' => now('UTC'), 'available_at' => now('UTC'), 'failed_at' => now('UTC'), 'last_error_code' => 'consumer_failure',
    ]);

    $this->artisan('ops:outbox-requeue', ['message' => $message->public_id])->assertExitCode(2);
    $this->artisan('ops:outbox-requeue', ['message' => 'nope', '--reason' => 'x'])->assertFailed();
    $this->artisan('ops:outbox-requeue', ['message' => $message->public_id, '--reason' => 'Consumer diperbaiki'])->assertSuccessful();

    expect($message->refresh()->failed_at)->toBeNull()
        ->and($message->attempts)->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'outbox.requeued')->count())->toBe(1);
});

test('credentials come only from the environment and the example file carries none', function (): void {
    $env = collect(file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->reject(fn (string $line): bool => str_starts_with(trim($line), '#'))
        ->mapWithKeys(fn (string $line): array => [trim(explode('=', $line, 2)[0]) => trim(explode('=', $line, 2)[1] ?? '')]);
    $keys = $env->keys()->filter(fn (string $key): bool => str_starts_with($key, 'INTEGRATION_') && (str_ends_with($key, '_HMAC_KEY') || str_ends_with($key, '_RUN_AS')));

    expect($keys)->toHaveCount(14)
        ->and($keys->every(fn (string $key): bool => $env->get($key) === ''))->toBeTrue()
        ->and(file_get_contents(base_path('config/integrations.php')))->not->toMatch('/hmac_key\'\s*=>\s*\'[^\']/')
        ->and(collect(config('integrations.connections'))->every(fn (array $connection): bool => ($connection['enabled'] ?? false) === false))->toBeTrue()
        ->and(collect(config('integrations.connections'))->every(fn (array $connection): bool => $connection['status'] !== 'PROVEN'))->toBeTrue();

    // A run never stores the manifest signature.
    expect(IntegrationRun::query()->count())->toBe(0);
});

test('restricted totals stay off the dashboard and manual runs are attributed to the person', function (): void {
    IntegrationRun::query()->create([
        'connection' => 'erp-receivables', 'mode' => 'inbound', 'file_name' => 'receivables.csv', 'status' => 'staged',
        'reconciliation' => ['rows' => ['manifest' => 2, 'batch' => 2, 'difference' => 0], 'invoice' => ['manifest' => '987654.32', 'batch' => '987654.31', 'difference' => '-0.01']],
        'reconciled' => false,
    ]);

    $this->actingAs($this->hr)->get(route('integrations.index'))->assertOk()
        ->assertSee('ada selisih (nilai terbatas)')
        ->assertDontSee('987654');

    $svc = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    IntegrationHarness::fake();
    IntegrationHarness::enable('mes-production', $svc);

    Livewire::actingAs($this->admin)->test('pages::integrations.index')->call('runNow', 'mes-production');

    expect(DB::table('audit_logs')->where('action', 'integration.run_triggered')->where('entity_id', 'mes-production')->value('actor_identifier'))->toBe($this->admin->public_id);
});
