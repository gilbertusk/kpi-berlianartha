<?php

declare(strict_types=1);

use App\Modules\Workflow\Application\WorkflowQuery;
use App\Modules\Workflow\Application\WorkflowService;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\PerformanceDataset;
use Tests\Support\WorkflowScenario;

/*
 * Step 19 performance probe finding: the approval board, inbox, and
 * dashboards looked up the review bypass once per scorecard (one query per
 * scorecard without a reviewer). A list now reads the bypasses once.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
});

function bypassQueries(Closure $operation): int
{
    $count = 0;
    DB::listen(function ($query) use (&$count): void {
        if (str_contains($query->sql, 'approval_stage_bypasses')) {
            $count++;
        }
    });

    $operation();

    return $count;
}

test('a list of scorecards reads the review bypasses once, not once per scorecard', function (): void {
    // 43 scorecards in review; only PROD has a reviewer, so most have a review gap.
    $dataset = new PerformanceDataset(2);
    $query = app(WorkflowQuery::class);

    $queries = bypassQueries(fn () => expect($query->board($dataset->period, $dataset->fixtures->kpi->author))->toHaveCount(43));

    expect($queries)->toBe(1);
});

test('the preloaded bypasses give the same answer as the per-scorecard lookup', function (): void {
    $scenario = new WorkflowScenario;
    $workflow = app(WorkflowService::class);
    $assignment = $scenario->scorecards->assignment('AR-001');

    expect($workflow->bypassFor($assignment, $workflow->reviewBypassesInForce()))->toBeNull()
        ->and($workflow->bypassFor($assignment))->toBeNull();

    $bypass = $scenario->bypass();
    $preloaded = $workflow->reviewBypassesInForce();

    expect($workflow->bypassFor($assignment, $preloaded)?->id)->toBe($bypass->id)
        ->and($workflow->bypassFor($assignment)?->id)->toBe($bypass->id)
        // W-001 has a reviewer: no bypass applies, preloaded or not.
        ->and($workflow->bypassFor($scenario->scorecards->assignment('W-001'), $preloaded))->toBeNull();

    $calibrated = $scenario->calibrate($scenario->hr(), 'AR-001');
    expect($calibrated->state->value)->toBe('calibrated');
});

test('a bypass that starts later is not in force today, preloaded or not', function (): void {
    $scenario = new WorkflowScenario;
    $workflow = app(WorkflowService::class);
    $assignment = $scenario->scorecards->assignment('AR-001');

    $scenario->bypass(from: '2026-10-20');

    expect($workflow->reviewBypassesInForce())->toBe([])
        ->and($workflow->bypassFor($assignment, $workflow->reviewBypassesInForce()))->toBeNull()
        ->and($workflow->bypassFor($assignment))->toBeNull();
});
