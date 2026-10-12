<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Operational probe used by `ops:queue-smoke`.
 *
 * Every execution increments a counter, so a duplicate delivery shows up as a
 * count above one instead of being silently absorbed.
 */
final class QueueSmokeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $token)
    {
        if (! Str::isUuid($token)) {
            throw new InvalidArgumentException('Queue smoke token must be a UUID.');
        }
    }

    public function cacheKey(): string
    {
        return self::cacheKeyFor($this->token);
    }

    public static function cacheKeyFor(string $token): string
    {
        return "ops:queue-smoke:{$token}";
    }

    public function handle(Repository $cache): void
    {
        $cache->increment($this->cacheKey());
    }
}
