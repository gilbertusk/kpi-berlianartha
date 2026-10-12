<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Storage;

use InvalidArgumentException;

final readonly class PendingObjectMetadata
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $disk,
        public string $path,
        public string $originalName,
        public ?string $contentType,
        public int $byteSize,
        public string $checksumAlgorithm,
        public string $checksum,
        public string $classification,
        public ?string $ownerType,
        public ?string $ownerId,
        public ?string $sourceSystem,
        public ?string $sourceReference,
        public ?string $createdBy,
        public array $metadata = [],
    ) {
        if ($disk === '' || $path === '' || $originalName === '') {
            throw new InvalidArgumentException('Disk, path, and original name are required.');
        }

        if (str_contains($path, '\\') || in_array('..', explode('/', $path), true)) {
            throw new InvalidArgumentException('Object path must be a normalized storage key.');
        }

        if ($byteSize < 0) {
            throw new InvalidArgumentException('Object size cannot be negative.');
        }

        if (! in_array($checksumAlgorithm, ['sha256', 'sha512'], true)) {
            throw new InvalidArgumentException('Unsupported checksum algorithm.');
        }

        if (! in_array($classification, ['public', 'internal', 'confidential', 'restricted'], true)) {
            throw new InvalidArgumentException('Unsupported data classification.');
        }
    }
}
