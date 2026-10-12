<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Outbox;

use DateTimeImmutable;

final readonly class OutboxEnvelope
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public string $messageId,
        public string $eventType,
        public int $eventVersion,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload,
        public array $headers,
        public DateTimeImmutable $occurredAt,
    ) {}
}
