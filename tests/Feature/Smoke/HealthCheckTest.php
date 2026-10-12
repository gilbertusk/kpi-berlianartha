<?php

declare(strict_types=1);

it('reports only a minimal healthy status', function (): void {
    $response = $this->getJson(route('health'));

    $response->assertOk()
        ->assertExactJson(['status' => 'ok']);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('is served on GET /health', function (): void {
    expect(route('health', absolute: false))->toBe('/health');

    $this->postJson('/health')->assertMethodNotAllowed();
});

it('does not start a session or set cookies', function (): void {
    $response = $this->get('/health');

    $response->assertOk();

    expect($response->headers->getCookies())->toBeEmpty();
});

it('does not leak secrets, environment, or configuration even in debug mode', function (): void {
    config([
        'app.debug' => true,
        'app.key' => 'base64:SENTINELAPPKEYSENTINELAPPKEYSENTINEL0000=',
        'database.connections.pgsql.password' => 'sentinel-db-password',
        'database.connections.pgsql.host' => 'sentinel-db-host',
        'mail.mailers.smtp.password' => 'sentinel-mail-password',
        'filesystems.disks.s3.secret' => 'sentinel-s3-secret',
    ]);

    $body = $this->get('/health')->assertOk()->getContent();

    expect($body)->toBeString();

    foreach ([
        'SENTINEL',
        'sentinel',
        config('app.env'),
        'APP_KEY',
        'password',
        'secret',
        'debug',
        'version',
        'laravel',
        'php',
    ] as $forbidden) {
        expect(str_contains(strtolower((string) $body), strtolower((string) $forbidden)))
            ->toBeFalse("Health response must not contain [{$forbidden}].");
    }
});

it('no longer exposes the framework default /up endpoint', function (): void {
    $this->get('/up')->assertNotFound();
});
