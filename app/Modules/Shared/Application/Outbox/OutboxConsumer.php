<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Outbox;

interface OutboxConsumer
{
    public function name(): string;

    public function supports(string $eventType, int $eventVersion): bool;

    /**
     * Consumers must use messageId as their idempotency key for external effects.
     */
    public function handle(OutboxEnvelope $message): void;
}
