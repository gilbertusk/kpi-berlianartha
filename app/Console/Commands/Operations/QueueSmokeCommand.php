<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Jobs\Operations\QueueSmokeJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

#[Signature('ops:queue-smoke {--timeout=30 : Seconds to wait for a worker} {--grace=3 : Extra seconds to watch for duplicate processing}')]
#[Description('Dispatch a probe job and verify a worker processes it exactly once')]
final class QueueSmokeCommand extends Command
{
    private const int POLL_INTERVAL_MS = 250;

    private const int COUNTER_TTL_SECONDS = 600;

    public function handle(Repository $cache): int
    {
        $token = (string) Str::uuid();
        $key = QueueSmokeJob::cacheKeyFor($token);
        $timeout = max(1, (int) $this->option('timeout'));

        $cache->add($key, 0, self::COUNTER_TTL_SECONDS);

        QueueSmokeJob::dispatch($token);

        $this->line(sprintf('Job dikirim ke koneksi [%s]; menunggu worker hingga %d detik.', config('queue.default'), $timeout));

        $deadline = microtime(true) + $timeout;

        while ($this->executions($cache, $key) < 1 && microtime(true) < $deadline) {
            Sleep::usleep(self::POLL_INTERVAL_MS * 1000);
        }

        if ($this->executions($cache, $key) < 1) {
            $this->error('Job smoke tidak diproses dalam batas waktu. Periksa worker dan koneksi queue.');

            return self::FAILURE;
        }

        Sleep::sleep(max(0, (int) $this->option('grace')));

        $executions = $this->executions($cache, $key);
        $cache->forget($key);

        if ($executions !== 1) {
            $this->error("Job smoke diproses {$executions} kali; seharusnya tepat 1 kali.");

            return self::FAILURE;
        }

        $this->info('Job smoke diproses tepat 1 kali.');

        return self::SUCCESS;
    }

    private function executions(Repository $cache, string $key): int
    {
        return (int) $cache->get($key, 0);
    }
}
