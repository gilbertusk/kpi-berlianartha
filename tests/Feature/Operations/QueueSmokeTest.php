<?php

declare(strict_types=1);

use App\Jobs\Operations\QueueSmokeJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

it('passes when the smoke job is processed exactly once', function (): void {
    config(['queue.default' => 'sync']);

    $this->artisan('ops:queue-smoke', ['--timeout' => 5, '--grace' => 0])
        ->expectsOutputToContain('diproses tepat 1 kali')
        ->assertSuccessful();
});

it('fails when no worker processes the smoke job', function (): void {
    Queue::fake();

    $this->artisan('ops:queue-smoke', ['--timeout' => 1, '--grace' => 0])
        ->expectsOutputToContain('tidak diproses')
        ->assertFailed();

    Queue::assertPushed(QueueSmokeJob::class, 1);
});

it('counts every execution so duplicate processing is detectable', function (): void {
    $job = new QueueSmokeJob((string) Str::uuid());
    Cache::add($job->cacheKey(), 0, 60);

    $job->handle(app('cache.store'));
    $job->handle(app('cache.store'));

    expect((int) Cache::get($job->cacheKey()))->toBe(2)
        ->and($job->tries)->toBe(1);
});

it('rejects a token that is not a UUID', function (): void {
    new QueueSmokeJob('../../not-a-token');
})->throws(InvalidArgumentException::class);
