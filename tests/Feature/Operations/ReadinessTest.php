<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

it('passes when database, cache, and storage are reachable', function (): void {
    Storage::fake('readiness-test');

    $this->artisan('ops:readiness', ['--disk' => 'readiness-test'])
        ->expectsOutputToContain('database')
        ->expectsOutputToContain('cache')
        ->expectsOutputToContain('storage')
        ->assertSuccessful();

    expect(Storage::disk('readiness-test')->allFiles())->toBeEmpty();
});

it('fails without leaking credentials when a dependency is unreachable', function (): void {
    Storage::fake('readiness-test');

    config([
        'database.connections.readiness_broken' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '1',
            'database' => 'missing',
            'username' => 'sentinel-user',
            'password' => 'sentinel-db-password',
        ],
    ]);

    $this->artisan('ops:readiness', ['--disk' => 'readiness-test', '--connection' => 'readiness_broken'])
        ->doesntExpectOutputToContain('sentinel-db-password')
        ->expectsOutputToContain('FAIL')
        ->assertFailed();
});

it('fails for a disk that is not configured', function (): void {
    $this->artisan('ops:readiness', ['--disk' => 'does-not-exist'])
        ->expectsOutputToContain('FAIL')
        ->assertFailed();
});
