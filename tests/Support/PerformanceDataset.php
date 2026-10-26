<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\ActualEvidence\Application\ActualAccess;
use App\Modules\ActualEvidence\Application\ActualEntry;
use App\Modules\ActualEvidence\Application\ActualService;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Scoring\Application\ScoreAccess;
use App\Modules\Scoring\Application\ScoringService;
use App\Modules\Target\Application\TargetService;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Illuminate\Support\Facades\Config;

/**
 * Synthetic, anonymous dataset for the Step 19 performance probe, built
 * through the real services: all 21 PRD teams, N employees per team, an
 * employee template (two employee KPIs, 60/40) per team, every target
 * approved, every actual entered, submitted, verified, and scored with the
 * TEST-ONLY policy, and the period in review.
 *
 * The size is NOT the agreed workload: DEC-007 (pilot users, concurrency,
 * volume) is open. It is the "representative" placeholder of
 * docs/qa/PERFORMANCE_REPORT.md, set with PERF_EMPLOYEES_PER_TEAM.
 */
final class PerformanceDataset
{
    public PeriodFixtures $fixtures;

    public AssessmentPeriod $period;

    public User $leader;

    public User $staff;

    public User $manager;

    public User $management;

    public User $auditor;

    /** @var array{teams: int, employees: int, targets: int, actuals: int, scorecards: int} */
    public array $size;

    public function __construct(public readonly int $employeesPerTeam)
    {
        AccessFixtures::seedStructure();
        Config::set('evidence.scanner', 'local-unscanned');
        $this->fixtures = new PeriodFixtures(new KpiFixtures);

        $teams = OrganizationUnit::query()->where('unit_type', UnitType::Team->value)->orderBy('code')->pluck('code')->all();

        foreach ($teams as $index => $team) {
            $first = $this->fixtures->kpi("PF-{$index}-A", $team, ['evidence_requirement' => 'none', 'evidence_description' => '']);
            $second = $this->fixtures->kpi("PF-{$index}-B", $team, ['evidence_requirement' => 'none', 'evidence_description' => '']);
            $this->fixtures->publishedTemplate("T-PF-{$index}", $team, [$first->id => '60', $second->id => '40']);

            for ($n = 1; $n <= $employeesPerTeam; $n++) {
                AccessFixtures::employee(null, $team, number: sprintf('PF-%02d-%03d', $index, $n));
            }
        }

        // Accounts exist before the period opens: the approver snapshot is taken then.
        $this->staff = AccessFixtures::user('Staff Woven');
        AccessFixtures::employee($this->staff, 'PROD-WOVEN', number: 'PF-STAFF');
        AccessFixtures::grant($this->staff, Role::Employee);
        $this->leader = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
        $this->manager = AccessFixtures::userWithRole(Role::DepartmentHead, 'PROD');
        $this->management = AccessFixtures::userWithRole(Role::Management, 'BAL');
        $this->auditor = AccessFixtures::userWithRole(Role::Auditor, 'BAL');

        $this->period = $this->fixtures->opened();
        $this->approveTargets();
        $this->period = app(PeriodService::class)->startProgress($this->fixtures->kpi->authorActor(), $this->period->id, $this->period->refresh()->lock_version);
        $actuals = $this->enterActuals();

        ScorecardScenario::enablePolicy();
        $scored = app(ScoringService::class)->calculate($this->fixtures->kpi->authorActor(), $this->period->id, ScoreAccess::everything(), 'Dataset performa');
        $this->period = app(PeriodService::class)->advance($this->fixtures->kpi->authorActor(), $this->period->id, $this->period->refresh()->lock_version, PeriodStatus::Review);

        $this->size = [
            'teams' => count($teams),
            'employees' => count($teams) * $employeesPerTeam + 1,
            'targets' => PeriodTarget::query()->where('assessment_period_id', $this->period->id)->count(),
            'actuals' => $actuals,
            'scorecards' => $scored['created'] + $scored['unchanged'],
        ];
    }

    private function approveTargets(): void
    {
        $service = app(TargetService::class);
        $scope = PeriodFixtures::everything();
        $targets = PeriodTarget::query()->where('assessment_period_id', $this->period->id)->with('definitionVersion')->get();

        foreach ($targets->chunk(200) as $chunk) {
            $service->saveDrafts($this->fixtures->kpi->authorActor(), $this->period->id, $scope, $chunk->map(fn (PeriodTarget $target) => $this->fixtures->entry($target, '100'))->values()->all());
        }

        $service->submit($this->fixtures->kpi->authorActor(), $this->period->id, $scope);
        $service->approve($this->fixtures->kpi->reviewerActor(), $this->period->id, $scope);
    }

    private function enterActuals(): int
    {
        $service = app(ActualService::class);
        $targets = PeriodTarget::query()->where('assessment_period_id', $this->period->id)->with('definitionVersion')->orderBy('id')->get();

        foreach ($targets->chunk(200) as $chunk) {
            $service->saveDrafts($this->fixtures->kpi->authorActor(), $this->period->id, ActualAccess::everything(), $chunk->map(fn (PeriodTarget $target): ActualEntry => new ActualEntry(
                reference: $target->public_id,
                targetId: $target->id,
                windowStart: PeriodFixtures::STARTS_ON,
                windowEnd: PeriodFixtures::ENDS_ON,
                value: (string) (80 + $target->id % 30),
                unit: (string) $target->definitionVersion->unit,
                note: null,
            ))->values()->all());
        }

        $service->submit($this->fixtures->kpi->authorActor(), $this->period->id, ActualAccess::everything());
        $service->verify(BrowserJourney::actor($this->leader), $this->period->id, ActualAccess::everything());

        return $targets->count();
    }
}
