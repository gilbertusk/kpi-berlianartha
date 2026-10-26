<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\AccessFixtures;
use Tests\Support\PerformanceDataset;

/*
 * Step 19 — server response probe for standard pages on the synthetic
 * dataset of PerformanceDataset. Measures, per page and role, the full
 * in-process request (routing, middleware, authorization, queries, Blade
 * and Livewire render) and the query count. It does not measure network,
 * browser rendering, or concurrent users: DEC-007 has no agreed workload.
 *
 * PRD NFR 002 gives 3 seconds for a standard page "pada beban yang
 * disepakati"; the probe fails a page whose p95 exceeds it. Results are
 * written to storage/framework/testing/performance.json (ignored by Git)
 * and summarised in docs/qa/PERFORMANCE_REPORT.md.
 *
 * Run: php artisan test --testsuite=Performance
 *      (PERF_EMPLOYEES_PER_TEAM, PERF_SAMPLES to change the workload)
 */

const STANDARD_PAGE_BUDGET_MS = 3000;

test('standard pages answer within the PRD budget on the synthetic dataset', function (): void {
    $this->travelTo(AccessFixtures::now());
    $employeesPerTeam = (int) (getenv('PERF_EMPLOYEES_PER_TEAM') ?: 15);
    $samples = (int) (getenv('PERF_SAMPLES') ?: 20);

    $seedStarted = hrtime(true);
    $dataset = new PerformanceDataset($employeesPerTeam);
    $seedSeconds = (hrtime(true) - $seedStarted) / 1e9;
    $period = $dataset->period;
    $hr = $dataset->fixtures->kpi->author;

    /** @var list<array{0: string, 1: User, 2: string}> $pages */
    $pages = [
        ['HR · dashboard', $hr, route('dashboard')],
        ['HR · periode', $hr, route('periods.show', $period)],
        ['HR · target', $hr, route('periods.targets', $period)],
        ['HR · aktual (semua unit)', $hr, route('actuals.entry', $period)],
        ['HR · scorecard periode', $hr, route('scorecards.period', $period)],
        ['HR · approval periode', $hr, route('approvals.period', $period)],
        ['HR · laporan', $hr, route('reports.index')],
        ['Management · dashboard', $dataset->management, route('dashboard')],
        ['Management · approval', $dataset->management, route('approvals.index')],
        ['Department Head · approval', $dataset->manager, route('approvals.index')],
        ['Team Leader · dashboard', $dataset->leader, route('dashboard')],
        ['Team Leader · aktual tim', $dataset->leader, route('actuals.entry', $period)],
        ['Team Leader · tim', $dataset->leader, route('team.index')],
        ['Employee · dashboard', $dataset->staff, route('dashboard')],
        ['Employee · aktual sendiri', $dataset->staff, route('actuals.entry', $period)],
        ['Auditor · kamus KPI', $dataset->auditor, route('kpi.definitions')],
    ];

    $results = [];
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    foreach ($pages as [$label, $user, $url]) {
        // Warm-up: compiled views, config, and first-hit caches.
        $this->actingAs($user)->get($url)->assertOk();

        $durations = [];
        $queries = 0;

        for ($i = 0; $i < $samples; $i++) {
            $count = 0;
            $started = hrtime(true);
            $this->actingAs($user)->get($url)->assertOk();
            $durations[] = (hrtime(true) - $started) / 1e6;
            $queries = max($queries, $count);
        }

        sort($durations);
        $results[] = [
            'page' => $label,
            'p50_ms' => round(percentile($durations, 50), 1),
            'p95_ms' => round(percentile($durations, 95), 1),
            'max_ms' => round(end($durations), 1),
            'queries' => $queries,
        ];
    }

    $report = [
        'measured_at' => (new DateTimeImmutable)->format(DATE_ATOM),
        'commit' => trim((string) shell_exec('git rev-parse --short HEAD')),
        'database' => DB::connection()->getDriverName(),
        'php' => PHP_VERSION,
        'samples_per_page' => $samples,
        'seed_seconds' => round($seedSeconds, 1),
        'dataset' => $dataset->size,
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        'results' => $results,
    ];

    File::ensureDirectoryExists(storage_path('framework/testing'));
    File::put(storage_path('framework/testing/performance.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fwrite(STDERR, PHP_EOL.json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);

    foreach ($results as $result) {
        expect($result['p95_ms'])->toBeLessThan(STANDARD_PAGE_BUDGET_MS, "{$result['page']} p95 {$result['p95_ms']} ms");
    }
});

/**
 * Nearest-rank percentile of a sorted list.
 *
 * @param  list<float>  $sorted
 */
function percentile(array $sorted, int $percent): float
{
    $rank = (int) ceil($percent / 100 * count($sorted));

    return $sorted[max(0, $rank - 1)];
}
