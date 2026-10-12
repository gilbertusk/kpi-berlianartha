<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Storage;

use App\Modules\Shared\Application\Audit\AuditPayloadSanitizer;
use App\Modules\Shared\Application\Storage\ObjectMetadataReference;
use App\Modules\Shared\Application\Storage\ObjectMetadataStore;
use App\Modules\Shared\Application\Storage\PendingObjectMetadata;
use App\Modules\Shared\Infrastructure\Persistence\Models\StoredObjectMetadata;

final readonly class EloquentObjectMetadataStore implements ObjectMetadataStore
{
    public function __construct(private AuditPayloadSanitizer $sanitizer) {}

    public function register(PendingObjectMetadata $metadata): ObjectMetadataReference
    {
        $record = StoredObjectMetadata::query()->create([
            'disk' => $metadata->disk,
            'path' => $metadata->path,
            'original_name' => $metadata->originalName,
            'content_type' => $metadata->contentType,
            'byte_size' => $metadata->byteSize,
            'checksum_algorithm' => $metadata->checksumAlgorithm,
            'checksum' => $metadata->checksum,
            'classification' => $metadata->classification,
            'owner_type' => $metadata->ownerType,
            'owner_id' => $metadata->ownerId,
            'source_system' => $metadata->sourceSystem,
            'source_reference' => $metadata->sourceReference,
            'status' => 'pending',
            'metadata' => $this->sanitizer->sanitize($metadata->metadata),
            'lock_version' => 1,
            'created_by' => $metadata->createdBy,
        ]);

        return new ObjectMetadataReference(
            publicId: $record->public_id,
            lockVersion: $record->lock_version,
            status: $record->status,
        );
    }
}
