<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Outbox;

use App\Modules\Shared\Application\Audit\AuditPayloadSanitizer;
use App\Modules\Shared\Domain\Exceptions\IdempotencyConflict;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;

final readonly class OutboxPublisher
{
    public function __construct(private AuditPayloadSanitizer $sanitizer) {}

    /**
     * @throws JsonException
     */
    public function publish(OutboxEvent $event): OutboxMessage
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Outbox events must be published inside the business transaction.');
        }

        $payload = $this->sanitizer->sanitize($event->payload);
        $headers = $this->sanitizer->sanitize($event->headers);
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $deduplicationHash = $event->deduplicationKey === null
            ? null
            : hash('sha256', $event->deduplicationKey);

        if ($deduplicationHash !== null) {
            $existing = OutboxMessage::query()
                ->where('deduplication_key', $deduplicationHash)
                ->first();

            if ($existing !== null) {
                if (
                    $existing->event_type !== $event->eventType
                    || $existing->event_version !== $event->eventVersion
                    || $existing->payload_hash !== $payloadHash
                ) {
                    throw new IdempotencyConflict('outbox:'.$event->eventType);
                }

                return $existing;
            }
        }

        return OutboxMessage::query()->create([
            'event_type' => $event->eventType,
            'event_version' => $event->eventVersion,
            'aggregate_type' => $event->aggregateType,
            'aggregate_id' => $event->aggregateId,
            'payload' => $payload,
            'payload_hash' => $payloadHash,
            'headers' => $headers,
            'deduplication_key' => $deduplicationHash,
            'occurred_at' => $event->occurredAt ?? now('UTC'),
            'available_at' => $event->availableAt ?? now('UTC'),
        ]);
    }
}
