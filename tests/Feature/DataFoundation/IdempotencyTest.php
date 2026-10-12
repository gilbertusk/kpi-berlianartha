<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Idempotency\IdempotencyService;
use App\Modules\Shared\Domain\Exceptions\IdempotencyConflict;
use App\Modules\Shared\Infrastructure\Persistence\Models\IdempotencyKey;
use App\Modules\Shared\Infrastructure\Persistence\Models\StoredObjectMetadata;

test('same idempotency key and fingerprint replays the stored result once', function (): void {
    $calls = 0;
    $service = app(IdempotencyService::class);

    $operation = function () use (&$calls): array {
        $calls++;

        return ['reference' => 'result-1'];
    };

    $first = $service->execute('actual.submit', 'request-key', 'payload-v1', $operation);
    $second = $service->execute('actual.submit', 'request-key', 'payload-v1', $operation);

    expect($first)->toBe(['reference' => 'result-1'])
        ->and($second)->toBe($first)
        ->and($calls)->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->firstOrFail()->key_hash)->toBe(hash('sha256', 'request-key'));
});

test('same idempotency key with a different fingerprint is rejected', function (): void {
    $service = app(IdempotencyService::class);

    $service->execute('actual.submit', 'request-key', 'payload-v1', fn (): array => ['ok' => true]);

    expect(fn () => $service->execute(
        'actual.submit',
        'request-key',
        'payload-v2',
        fn (): array => ['ok' => false],
    ))->toThrow(IdempotencyConflict::class);
});

test('failed idempotent command rolls back both state and processing key', function (): void {
    $service = app(IdempotencyService::class);

    expect(fn () => $service->execute(
        'actual.submit',
        'failed-key',
        'payload',
        function (): array {
            StoredObjectMetadata::factory()->create();

            throw new RuntimeException('synthetic failure');
        },
    ))->toThrow(RuntimeException::class);

    expect(StoredObjectMetadata::query()->count())->toBe(0)
        ->and(IdempotencyKey::query()->count())->toBe(0);
});
