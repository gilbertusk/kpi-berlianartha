<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Audit;

use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class AuditRecorder
{
    public function __construct(private AuditPayloadSanitizer $sanitizer) {}

    public function record(AuditEntry $entry): AuditLog
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Business audit must be recorded inside the business transaction.');
        }

        return AuditLog::query()->create([
            'actor_identifier' => $entry->context->actorIdentifier,
            'actor_type' => $entry->context->actorType,
            'actor_role' => $entry->context->actorRole,
            'actor_scope' => $entry->context->actorScope === null
                ? null
                : $this->sanitizer->sanitize($entry->context->actorScope),
            'action' => $entry->action,
            'entity_type' => $entry->entityType,
            'entity_id' => $entry->entityId,
            'before_values' => $entry->before === null ? null : $this->sanitizer->sanitize($entry->before),
            'after_values' => $entry->after === null ? null : $this->sanitizer->sanitize($entry->after),
            'reason' => $entry->reason,
            'source' => $entry->context->source,
            'source_reference' => $entry->context->sourceReference,
            'correlation_id' => $entry->context->correlationId,
            'request_id' => $entry->context->requestId,
            'occurred_at' => $entry->occurredAt ?? now('UTC'),
        ]);
    }
}
