<?php

declare(strict_types=1);

namespace App\Modules\TeamReview\Application;

/**
 * Input of a corrective action: what, who (PIC), until when, how urgent.
 */
final readonly class ActionDraft
{
    public function __construct(
        public string $action,
        public int $ownerUserId,
        public string $dueOn,
        public string $priority,
    ) {}
}
