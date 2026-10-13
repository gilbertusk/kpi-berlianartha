<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\ActualEvidence\Application\ActualAccess;
use App\Modules\ActualEvidence\Application\ActualEntry;
use App\Modules\ActualEvidence\Application\ActualService;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Scoring\Application\ScoreAccess;
use App\Modules\Scoring\Application\ScoringService;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Closure;
use Illuminate\Support\Facades\Config;

/**
 * A November period in progress, built through the real services:
 *
 * - PROD-WOVEN employees W-001 (account "staff", Employee role) and W-002
 *   share an employee template: SC-QUALITY (employee KPI) 60% and SC-TEAM
 *   (team KPI) 40%. The team template of PROD-WOVEN holds SC-TEAM at 100%.
 * - ACC-AR employee AR-001 has an employee template with SC-DSO at 100%.
 * - Every target is approved at 100. KPI rules are the KpiDefinitionFactory
 *   fixture (ratio to target, floor 0, cap 120, half up 2 decimals, missing
 *   data held). The scorecard policy is the TEST-ONLY fixture, enabled per
 *   test with {@see enablePolicy()}.
 */
final class ScorecardScenario
{
    public PeriodFixtures $fixtures;

    public AssessmentPeriod $period;

    public User $staff;

    public User $leader;

    public KpiDefinitionVersion $quality;

    public KpiDefinitionVersion $team;

    /**
     * @param  (Closure(self): void)|null  $beforeOpen  extra setup that must exist when the period snapshot is taken
     */
    public function __construct(?Closure $beforeOpen = null)
    {
        AccessFixtures::seedStructure();
        Config::set('evidence.scanner', 'local-unscanned');

        $this->fixtures = new PeriodFixtures(new KpiFixtures);

        $this->staff = AccessFixtures::user('Staff Woven');
        AccessFixtures::employee($this->staff, 'PROD-WOVEN', number: 'W-001');
        AccessFixtures::grant($this->staff, Role::Employee);
        AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-002');
        AccessFixtures::employee(null, 'ACC-AR', number: 'AR-001');
        $this->leader = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');

        $this->quality = $this->fixtures->kpi('SC-QUALITY', 'PROD-WOVEN', ['evidence_requirement' => 'optional', 'evidence_description' => 'Laporan mutu']);
        $this->team = $this->fixtures->kpi('SC-TEAM', 'PROD-WOVEN', ['evaluation_subject' => 'team', 'evidence_requirement' => 'none', 'evidence_description' => '']);
        $dso = $this->fixtures->kpi('SC-DSO', 'ACC-AR', ['evidence_requirement' => 'none', 'evidence_description' => '']);

        $this->fixtures->publishedTemplate('T-SC-WOVEN', 'PROD-WOVEN', [$this->quality->id => '60', $this->team->id => '40']);
        $this->fixtures->publishedTemplate('T-SC-WOVEN-TEAM', 'PROD-WOVEN', [$this->team->id => '100'], 'team');
        $this->fixtures->publishedTemplate('T-SC-AR', 'ACC-AR', [$dso->id => '100']);

        if ($beforeOpen !== null) {
            $beforeOpen($this);
        }

        $this->period = $this->fixtures->inProgress();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function enablePolicy(array $overrides = []): void
    {
        Config::set('scoring.policies', [ScoringFixtures::policyConfig($overrides)]);
    }

    public function hr(): User
    {
        return $this->fixtures->kpi->author;
    }

    public function hrActor(): Actor
    {
        return $this->fixtures->kpi->authorActor();
    }

    public function target(string $kpi, string $subject): PeriodTarget
    {
        return $this->fixtures->target($this->period, $kpi, $subject);
    }

    public function assignment(string $subjectCode): PeriodAssignment
    {
        return PeriodAssignment::query()->where('assessment_period_id', $this->period->id)->where('subject_code', $subjectCode)->firstOrFail();
    }

    /**
     * HR saves a draft; null value = N/A with a note.
     */
    public function enter(PeriodTarget $target, ?string $value, string $from = '2026-11-01', string $to = '2026-11-30'): void
    {
        app(ActualService::class)->saveDrafts($this->hrActor(), $this->period->id, ActualAccess::everything(), [new ActualEntry(
            reference: $target->public_id,
            targetId: $target->id,
            windowStart: $from,
            windowEnd: $to,
            value: $value,
            unit: (string) $target->definitionVersion->unit,
            note: $value === null ? 'Tidak berlaku bulan ini' : null,
        )]);
    }

    /**
     * HR submits every draft it wrote; the team leader verifies them.
     */
    public function submitAndVerify(): void
    {
        app(ActualService::class)->submit($this->hrActor(), $this->period->id, ActualAccess::everything());
        app(ActualService::class)->verify(new Actor($this->leader->id, Actor::system('test')->audit), $this->period->id, ActualAccess::everything());
    }

    /**
     * W-001: quality 95.5, team 110. W-002: quality missing.
     */
    public function standardActuals(): void
    {
        $this->enter($this->target('SC-QUALITY', 'W-001'), '95.5');
        $this->enter($this->target('SC-TEAM', 'PROD-WOVEN'), '110');
        $this->enter($this->target('SC-DSO', 'AR-001'), '80');
        $this->submitAndVerify();
    }

    /**
     * @return array{created: int, unchanged: int, complete: int, incomplete: int}
     */
    public function calculate(string $reason = 'Perhitungan uji', ?ScoreAccess $access = null): array
    {
        return app(ScoringService::class)->calculate($this->hrActor(), $this->period->id, $access ?? ScoreAccess::everything(), $reason);
    }
}
