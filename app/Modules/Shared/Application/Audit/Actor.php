<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Audit;

/**
 * Who performs a use case: the internal user reference for created_by style
 * columns plus the audit context recorded with the business change.
 */
final readonly class Actor
{
    public function __construct(
        public ?int $userId,
        public AuditContext $audit,
    ) {}

    public static function system(string $source, ?string $sourceReference = null): self
    {
        return new self(null, AuditContext::system($source, $sourceReference));
    }
}
