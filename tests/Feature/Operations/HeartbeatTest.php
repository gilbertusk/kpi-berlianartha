<?php

declare(strict_types=1);

use App\Support\Operations\Heartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;

// Heartbeats live in the shared cache store. With CACHE_STORE=redis the suite
// runs against a dedicated Redis database (scripts/dev.sh test-pgsql uses DB 14)
// or an ephemeral CI Redis service, so flushing it is safe.
beforeEach(fn () => Cache::flush());

it('reports a process as fresh right after it beats and stale once it stops', function (): void {
    $heartbeat = app(Heartbeat::class);

    expect($heartbeat->isFresh('scheduler', 150))->toBeFalse();

    $heartbeat->beat('scheduler');

    expect($heartbeat->isFresh('scheduler', 150))->toBeTrue();

    $this->travel(151)->seconds();

    expect($heartbeat->isFresh('scheduler', 150))->toBeFalse();
});

it('rejects unknown process names', function (): void {
    app(Heartbeat::class)->beat('anything');
})->throws(InvalidArgumentException::class);

it('records and checks heartbeats through the console command', function (): void {
    $this->artisan('ops:heartbeat', ['process' => 'scheduler', '--check' => true])
        ->assertFailed();

    $this->artisan('ops:heartbeat', ['process' => 'scheduler'])->assertSuccessful();

    $this->artisan('ops:heartbeat', ['process' => 'scheduler', '--check' => true])
        ->assertSuccessful();

    $this->artisan('ops:heartbeat', ['process' => 'unknown'])->assertFailed();
});

it('records a worker heartbeat on every queue worker loop', function (): void {
    expect(app(Heartbeat::class)->isFresh('worker', 60))->toBeFalse();

    event(new Looping('redis', 'default'));

    expect(app(Heartbeat::class)->isFresh('worker', 60))->toBeTrue();
});

it('schedules the scheduler heartbeat every minute', function (): void {
    $events = collect(app(Schedule::class)->events());

    $heartbeat = $events->first(fn ($event): bool => str_contains((string) $event->command, 'ops:heartbeat scheduler'));

    expect($heartbeat)->not->toBeNull()
        ->and($heartbeat->expression)->toBe('* * * * *');
});
