<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Operational signals (Step 18): slow queries and failed jobs as structured
 * log events, and the download rate limit.
 */
final class ObservabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $threshold = max(1, (int) Config::get('observability.slow_query_ms', 500));

        DB::listen(function (QueryExecuted $query) use ($threshold): void {
            if ($query->time >= $threshold) {
                // SQL text only: bindings can hold personal data.
                Log::warning('db.slow_query', ['connection' => $query->connectionName, 'duration_ms' => $query->time, 'sql' => mb_substr($query->sql, 0, 500)]);
            }
        });

        Event::listen(function (JobFailed $event): void {
            Log::error('queue.job_failed', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'exception' => $event->exception::class,
            ]);
        });

        // Evidence and export files: generous for people, a brake on scraping.
        RateLimiter::for('downloads', fn (Request $request): Limit => Limit::perMinute(60)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
