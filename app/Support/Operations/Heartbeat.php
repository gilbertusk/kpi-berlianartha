<?php

declare(strict_types=1);

namespace App\Support\Operations;

use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;

/**
 * Liveness heartbeat for long-running processes (queue worker, scheduler).
 *
 * Each process records the time of its latest loop in the cache, keyed by
 * process name and host, so a container healthcheck running on the same host
 * can verify that the process is still making progress.
 */
final class Heartbeat
{
    public const array PROCESSES = ['scheduler', 'worker'];

    private const int TTL_SECONDS = 86400;

    public function __construct(private readonly Repository $cache) {}

    public function beat(string $process): void
    {
        $this->cache->put($this->key($process), now()->getTimestamp(), self::TTL_SECONDS);
    }

    public function isFresh(string $process, int $maxAgeSeconds): bool
    {
        $lastBeat = $this->cache->get($this->key($process));

        // Redis returns numeric cache values as strings; the array store keeps ints.
        return is_numeric($lastBeat) && now()->getTimestamp() - (int) $lastBeat <= $maxAgeSeconds;
    }

    private function key(string $process): string
    {
        if (! in_array($process, self::PROCESSES, true)) {
            throw new InvalidArgumentException("Unknown heartbeat process [{$process}].");
        }

        return sprintf('ops:heartbeat:%s:%s', $process, gethostname() ?: 'unknown-host');
    }
}
