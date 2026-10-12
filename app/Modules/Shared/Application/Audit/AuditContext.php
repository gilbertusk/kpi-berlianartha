<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Audit;

use Illuminate\Support\Str;

final readonly class AuditContext
{
    /**
     * @param  array<string, bool|float|int|string|null>|null  $actorScope
     */
    public function __construct(
        public ?string $actorIdentifier,
        public string $actorType,
        public ?string $actorRole,
        public ?array $actorScope,
        public string $source,
        public ?string $sourceReference,
        public string $correlationId,
        public ?string $requestId,
    ) {}

    public static function system(string $source, ?string $sourceReference = null): self
    {
        return new self(
            actorIdentifier: null,
            actorType: 'system',
            actorRole: null,
            actorScope: null,
            source: $source,
            sourceReference: $sourceReference,
            correlationId: (string) Str::ulid(),
            requestId: null,
        );
    }
}
