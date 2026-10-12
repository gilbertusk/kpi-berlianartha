<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Storage\ObjectMetadataStore;
use App\Modules\Shared\Application\Storage\PendingObjectMetadata;
use App\Modules\Shared\Domain\Exceptions\ConcurrencyConflict;
use App\Modules\Shared\Infrastructure\Persistence\Models\StoredObjectMetadata;

test('object metadata is registered without uploading evidence or retaining secrets', function (): void {
    $reference = app(ObjectMetadataStore::class)->register(new PendingObjectMetadata(
        disk: 's3',
        path: 'pending/2026/object.txt',
        originalName: 'object.txt',
        contentType: 'text/plain',
        byteSize: 12,
        checksumAlgorithm: 'sha256',
        checksum: hash('sha256', 'hello world!'),
        classification: 'confidential',
        ownerType: 'scorecard',
        ownerId: 'scorecard-1',
        sourceSystem: 'manual',
        sourceReference: 'upload-1',
        createdBy: 'user-1',
        metadata: ['description' => 'test', 'access_token' => 'hidden'],
    ));

    $record = StoredObjectMetadata::query()
        ->where('public_id', $reference->publicId)
        ->firstOrFail();

    expect($reference->status)->toBe('pending')
        ->and($reference->lockVersion)->toBe(1)
        ->and($record->metadata)->toBe([
            'description' => 'test',
            'access_token' => '[REDACTED]',
        ]);
});

test('optimistic update increments version and rejects stale writers', function (): void {
    $record = StoredObjectMetadata::factory()->create();
    $lock = app(OptimisticLock::class);

    $updated = $lock->update($record, 1, ['status' => 'available']);

    expect($updated->status)->toBe('available')
        ->and($updated->lock_version)->toBe(2);

    expect(fn () => $lock->update($record, 1, ['status' => 'deleted']))
        ->toThrow(ConcurrencyConflict::class);

    expect($updated->fresh()->status)->toBe('available');
});
