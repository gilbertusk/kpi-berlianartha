<?php

declare(strict_types=1);

it('boots the application', function (): void {
    expect(app()->isBooted())->toBeTrue()
        ->and(config('app.name'))->toBeString()->not->toBeEmpty();
});

it('persists time in UTC and presents business time in Asia/Jakarta (ADR-0007)', function (): void {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC')
        ->and(config('app.business_timezone'))->toBe('Asia/Jakarta');
});

it('ships an .env.example that targets the agreed platform without secrets', function (): void {
    $lines = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    expect($lines)->toBeArray();

    $env = collect($lines)
        ->reject(fn (string $line): bool => str_starts_with(trim($line), '#'))
        ->mapWithKeys(function (string $line): array {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');

            return [trim($key) => trim($value)];
        });

    expect($env->get('DB_CONNECTION'))->toBe('pgsql')
        ->and($env->get('CACHE_STORE'))->toBe('redis')
        ->and($env->get('QUEUE_CONNECTION'))->toBe('redis')
        ->and($env->get('APP_TIMEZONE'))->toBe('Asia/Jakarta')
        ->and($env->get('AWS_USE_PATH_STYLE_ENDPOINT'))->toBe('true');

    foreach (['APP_KEY', 'DB_PASSWORD', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'] as $secret) {
        expect($env->get($secret))->toBe('', "{$secret} must be empty in .env.example.");
    }
});
