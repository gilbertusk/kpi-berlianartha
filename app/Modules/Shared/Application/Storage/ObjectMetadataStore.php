<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Storage;

interface ObjectMetadataStore
{
    public function register(PendingObjectMetadata $metadata): ObjectMetadataReference;
}
