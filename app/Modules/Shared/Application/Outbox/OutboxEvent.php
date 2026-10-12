<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Outbox;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OutboxEvent
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public string $eventType,
        public int $eventVersion,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload,
        public array $headers = [],
        public ?string $deduplicationKey = null,
        public ?DateTimeImmutable $occurredAt = null,
        public ?DateTimeImmutable $availableAt = null,
    ) {
        if ($eventType === '' || $eventVersion < 1 || $aggregateType === '' || $aggregateId === '') {
            throw new InvalidArgumentException('Outbox event identity and positive version are required.');
        }
    }
}
