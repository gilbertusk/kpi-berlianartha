<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Modules\Notification\Domain\NotificationCategory;

/**
 * What to tell a recipient. Titles and bodies name the task and the
 * record, never a score, a rating, or the text of a restricted note: the
 * linked page shows those after its own authorization check.
 */
final readonly class NotificationMessage
{
    /**
     * @param  array<string, string>  $routeParameters
     */
    public function __construct(
        public NotificationCategory $category,
        public string $title,
        public string $body,
        public string $deduplicationKey,
        public ?string $routeName = null,
        public array $routeParameters = [],
    ) {}
}
