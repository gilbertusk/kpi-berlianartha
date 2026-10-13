<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Application;

/**
 * One actual to save as a draft. `value` null means "not applicable".
 * `expectedLockVersion` is the lock version of the draft the caller saw, 0
 * when it saw none, or null to skip the check (import compares state itself).
 */
final readonly class ActualEntry
{
    public function __construct(
        public string $reference,
        public int $targetId,
        public string $windowStart,
        public string $windowEnd,
        public ?string $value,
        public string $unit,
        public ?string $note = null,
        public ?int $expectedLockVersion = null,
        public ?string $sourceSystem = null,
        public ?string $sourceReference = null,
    ) {}
}
