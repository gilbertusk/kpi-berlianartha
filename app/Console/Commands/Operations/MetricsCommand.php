<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Support\Operations\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * A snapshot of operational metrics for an external monitor (Prometheus
 * textfile collector or a log shipper): queue depth and failures, outbox
 * backlog and age, process heartbeat ages, export, notification, and
 * integration failures. Counts and ages only; never row data.
 */
#[Signature('ops:metrics {--format=json : json|prometheus}')]
#[Description('Print operational metrics (counts and ages only)')]
final class MetricsCommand extends Command
{
    public function handle(Heartbeat $heartbeat): int
    {
        $now = CarbonImmutable::now();
        $since = $now->subDay();
        // Each probe on its own: a failing database or cache yields a missing
        // metric (null / unavailable) and a logged exception, not a crash.
        $probes = [
            'kpi_queue_size' => fn (): int => Queue::connection()->size(),
            'kpi_failed_jobs_total' => fn (): int => DB::table('failed_jobs')->count(),
            'kpi_outbox_pending' => fn (): int => DB::table('outbox_messages')->whereNull('processed_at')->whereNull('failed_at')->count(),
            'kpi_outbox_dead_letter' => fn (): int => DB::table('outbox_messages')->whereNull('processed_at')->whereNotNull('failed_at')->count(),
            'kpi_outbox_oldest_pending_age_seconds' => fn (): int => $this->age(DB::table('outbox_messages')->whereNull('processed_at')->whereNull('failed_at')->min('occurred_at'), $now),
            'kpi_scheduler_heartbeat_age_seconds' => fn (): ?int => $heartbeat->age('scheduler'),
            'kpi_worker_heartbeat_age_seconds' => fn (): ?int => $heartbeat->age('worker'),
            'kpi_report_exports_failed_24h' => fn (): int => DB::table('report_exports')->where('status', 'failed')->where('updated_at', '>=', $since)->count(),
            'kpi_report_exports_in_flight' => fn (): int => DB::table('report_exports')->whereIn('status', ['queued', 'running'])->count(),
            'kpi_notification_deliveries_failed_24h' => fn (): int => DB::table('notification_deliveries')->where('status', 'failed')->where('updated_at', '>=', $since)->count(),
            'kpi_integration_runs_failed' => fn (): int => DB::table('integration_runs')->where('status', 'failed')->count(),
            'kpi_integration_runs_dead_letter' => fn (): int => DB::table('integration_runs')->where('status', 'dead_letter')->count(),
            'kpi_integration_runs_quarantined_24h' => fn (): int => DB::table('integration_runs')->where('status', 'quarantined')->where('created_at', '>=', $since)->count(),
            'kpi_integration_exceptions_open' => fn (): int => DB::table('integration_exceptions')->where('status', 'open')->count(),
            'kpi_import_batches_staged' => fn (): int => DB::table('actual_import_batches')->where('status', 'staged')->count()
                + DB::table('production_batches')->where('status', 'staged')->count()
                + DB::table('ar_snapshots')->where('status', 'staged')->count(),
        ];
        $metrics = array_map($this->safely(...), $probes);

        if ($this->option('format') === 'prometheus') {
            foreach ($metrics as $name => $value) {
                $this->line($value === null ? "# {$name} unavailable" : "{$name} {$value}");
            }
        } else {
            $this->line((string) json_encode(['generated_at' => $now->toIso8601String(), 'metrics' => $metrics]));
        }

        return self::SUCCESS;
    }

    private function age(mixed $oldest, CarbonImmutable $now): int
    {
        return $oldest === null ? 0 : max(0, (int) CarbonImmutable::parse((string) $oldest, 'UTC')->diffInSeconds($now, true));
    }

    /**
     * @param  callable(): ?int  $probe
     */
    private function safely(callable $probe): ?int
    {
        try {
            return $probe();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
