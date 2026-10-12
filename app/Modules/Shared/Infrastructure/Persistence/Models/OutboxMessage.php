<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence\Models;

use App\Modules\Shared\Application\Outbox\OutboxEnvelope;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $event_type
 * @property int $event_version
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property array<string, mixed> $payload
 * @property array<string, mixed> $headers
 * @property string $payload_hash
 * @property int $attempts
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $failed_at
 */
final class OutboxMessage extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    public function toEnvelope(): OutboxEnvelope
    {
        return new OutboxEnvelope(
            messageId: $this->public_id,
            eventType: $this->event_type,
            eventVersion: $this->event_version,
            aggregateType: $this->aggregate_type,
            aggregateId: $this->aggregate_id,
            payload: $this->payload,
            headers: $this->headers,
            occurredAt: $this->occurred_at->toDateTimeImmutable(),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_version' => 'integer',
            'payload' => 'array',
            'headers' => 'array',
            'attempts' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
