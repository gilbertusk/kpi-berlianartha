<?php

declare(strict_types=1);

namespace App\Modules\Target\Application;

/**
 * One target value to save as a draft. `reference` identifies the entry to
 * the caller in row errors (the form row, or the line of an imported file).
 * `expectedLockVersion` is the lock version of the draft the caller saw, or 0
 * when it saw none; null skips the check (import compares state itself).
 */
final readonly class TargetEntry
{
    public function __construct(
        public string $reference,
        public int $targetId,
        public string $value,
        public string $unit,
        public ?string $sourceNote = null,
        public ?int $expectedLockVersion = null,
    ) {}
}
