<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Workflow\Application\BypassService;
use App\Modules\Workflow\Application\WorkflowService;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ApprovalStageBypass;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;
use Closure;

/**
 * The scorecard scenario moved into REVIEW, with an approver chain for the
 * PROD-WOVEN scorecards and a deliberate gap for ACC-AR:
 *
 * - review: the Department Head of PROD ("manager"). ACC has none, so the
 *   AR-001 scorecard has no reviewer;
 * - calibration: the two HR Performance accounts of the KPI fixtures;
 * - final approval: Management on the company.
 *
 * Every covered scorecard is complete (W-001 quality 95.5, W-002 quality 90,
 * team 110, DSO 80) under the TEST-ONLY scorecard policy. Nothing here is a
 * business decision.
 */
final class WorkflowScenario
{
    public ScorecardScenario $scorecards;

    public AssessmentPeriod $period;

    public User $manager;

    public User $management;

    /**
     * @param  (Closure(ScorecardScenario): void)|null  $beforeOpen  extra accounts or roles for the approver snapshot
     */
    public function __construct(bool $enterReview = true, ?Closure $beforeOpen = null)
    {
        $manager = null;
        $management = null;

        $this->scorecards = new ScorecardScenario(function (ScorecardScenario $scorecards) use (&$manager, &$management, $beforeOpen): void {
            $manager = AccessFixtures::userWithRole(Role::DepartmentHead, 'PROD');
            $management = AccessFixtures::userWithRole(Role::Management, 'BAL');

            if ($beforeOpen !== null) {
                $beforeOpen($scorecards);
            }
        });

        /** @var User $manager */
        /** @var User $management */
        $this->manager = $manager;
        $this->management = $management;

        ScorecardScenario::enablePolicy();

        $this->scorecards->enter($this->scorecards->target('SC-QUALITY', 'W-001'), '95.5');
        $this->scorecards->enter($this->scorecards->target('SC-QUALITY', 'W-002'), '90');
        $this->scorecards->enter($this->scorecards->target('SC-TEAM', 'PROD-WOVEN'), '110');
        $this->scorecards->enter($this->scorecards->target('SC-DSO', 'AR-001'), '80');
        $this->scorecards->submitAndVerify();
        $this->scorecards->calculate();

        $this->period = $this->scorecards->period->refresh();

        if ($enterReview) {
            $this->advance(PeriodStatus::Review);
        }
    }

    public function hr(): User
    {
        return $this->scorecards->hr();
    }

    public function secondHr(): User
    {
        return $this->scorecards->fixtures->kpi->reviewer;
    }

    public static function actor(User $user): Actor
    {
        return new Actor($user->id, Actor::system('test')->audit);
    }

    public function advance(PeriodStatus $to): AssessmentPeriod
    {
        $this->period = app(PeriodService::class)->advance(self::actor($this->hr()), $this->period->id, $this->period->refresh()->lock_version, $to);

        return $this->period;
    }

    public function review(string $subjectCode): ScorecardReview
    {
        return ScorecardReview::query()->where('period_assignment_id', $this->scorecards->assignment($subjectCode)->id)->firstOrFail();
    }

    public function approve(User $user, string $subjectCode, ?string $comment = null): ScorecardReview
    {
        $review = $this->review($subjectCode);

        return app(WorkflowService::class)->approve(self::actor($user), $user, $review->id, $review->lock_version, $comment);
    }

    public function calibrate(User $user, string $subjectCode, ?string $score = null, ?string $reason = null): ScorecardReview
    {
        $review = $this->review($subjectCode);

        return app(WorkflowService::class)->calibrate(self::actor($user), $user, $review->id, $review->lock_version, $score, $reason);
    }

    /**
     * Review by the manager, calibration by HR, final approval by Management.
     */
    public function approveFully(string $subjectCode): ScorecardReview
    {
        $this->approve($this->manager, $subjectCode);
        $this->calibrate($this->hr(), $subjectCode);

        return $this->approve($this->management, $subjectCode);
    }

    public function bypass(string $unitCode = 'ACC-AR', string $from = AccessFixtures::TODAY, ?string $until = null): ApprovalStageBypass
    {
        return app(BypassService::class)->register(self::actor($this->hr()), $this->hr(), AccessFixtures::unit($unitCode)->id, 'TEST-ONLY sign-off bypass', 'Team tanpa reviewer', $from, $until);
    }

    /**
     * Takes every scorecard to final approval; AR-001 through a bypass.
     */
    public function approveEverything(): void
    {
        $this->bypass();

        foreach (['W-001', 'W-002', 'PROD-WOVEN'] as $subject) {
            $this->approveFully($subject);
        }

        $this->calibrate($this->hr(), 'AR-001');
        $this->approve($this->management, 'AR-001');
    }

    public function actual(string $kpiCode, string $subjectCode): KpiActual
    {
        return KpiActual::query()->where('period_target_id', $this->scorecards->target($kpiCode, $subjectCode)->id)->firstOrFail();
    }

    public static function flushAccess(): void
    {
        app(AccessControl::class)->flush();
    }
}
