<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Storage;

final readonly class ObjectMetadataReference
{
    public function __construct(
        public string $publicId,
        public int $lockVersion,
        public string $status,
    ) {}
}
