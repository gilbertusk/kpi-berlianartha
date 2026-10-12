<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Audit;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function __construct(
        public string $action,
        public string $entityType,
        public string $entityId,
        public ?array $before,
        public ?array $after,
        public ?string $reason,
        public AuditContext $context,
        public ?DateTimeImmutable $occurredAt = null,
    ) {
        if (trim($action) === '' || trim($entityType) === '' || trim($entityId) === '') {
            throw new InvalidArgumentException('Audit action, entity type, and entity ID are required.');
        }
    }
}
