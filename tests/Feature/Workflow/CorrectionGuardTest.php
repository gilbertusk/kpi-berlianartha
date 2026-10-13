<?php

declare(strict_types=1);

use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Workflow\Application\CorrectionService;
use Tests\Support\AccessFixtures;
use Tests\Support\ScorecardScenario;
use Tests\Support\WorkflowScenario;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
});

test('the requester cannot approve their own request even when holding the approver role', function (): void {
    $scenario = new WorkflowScenario(true, function (ScorecardScenario $scorecards): void {
        AccessFixtures::grant($scorecards->hr(), Role::Management, 'BAL');
    });
    $corrections = app(CorrectionService::class);
    $hr = $scenario->hr();

    $request = $corrections->request(WorkflowScenario::actor($hr), $hr, $scenario->actual('SC-QUALITY', 'W-001')->id, '80', null, 'Salah input');

    expect(AccessFixtures::violatedRule(fn () => $corrections->approve(WorkflowScenario::actor($hr), $hr, $request->id, $request->lock_version)))->toBe('two_person_rule')
        ->and(KpiActualVersion::query()->where('kpi_actual_id', $request->kpi_actual_id)->count())->toBe(1);
});

test('corrections are not the way to change actuals while the period is in progress', function (): void {
    $scenario = new WorkflowScenario(false);
    $hr = $scenario->hr();

    expect(AccessFixtures::violatedRule(fn () => app(CorrectionService::class)->request(WorkflowScenario::actor($hr), $hr, $scenario->actual('SC-QUALITY', 'W-001')->id, '80', null, 'Alasan')))->toBe('correction_not_applicable');
});
