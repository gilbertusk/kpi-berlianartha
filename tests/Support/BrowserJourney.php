<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\ActualEvidence\Application\ActualAccess;
use App\Modules\ActualEvidence\Application\ActualService;
use App\Modules\ActualEvidence\Application\EvidenceService;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use App\Modules\Workflow\Application\BypassService;
use App\Modules\Workflow\Application\WorkflowService;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;

/**
 * The {@see ScorecardScenario} prepared for the browser journey: the same
 * approver chain as {@see WorkflowScenario} (Department Head PROD reviews,
 * HR calibrates, Management approves), the TEST-ONLY scorecard policy, and
 * every actual of the period still empty so the journey enters W-001
 * through the UI. Helpers move the other scorecards of the period through
 * the services, so the journey can finalize and lock the period.
 */
final class BrowserJourney
{
    public ScorecardScenario $scorecards;

    public User $manager;

    public User $management;

    public function __construct()
    {
        $manager = null;
        $management = null;

        $this->scorecards = new ScorecardScenario(function () use (&$manager, &$management): void {
            $manager = AccessFixtures::userWithRole(Role::DepartmentHead, 'PROD');
            $management = AccessFixtures::userWithRole(Role::Management, 'BAL');
        });

        /** @var User $manager */
        /** @var User $management */
        $this->manager = $manager;
        $this->management = $management;

        ScorecardScenario::enablePolicy();
    }

    public static function evidencePdf(): string
    {
        return base_path('tests/Fixtures/browser/laporan-mutu.pdf');
    }

    /**
     * The employee attaches the synthetic PDF to the own draft actual.
     */
    public function attachEvidence(PeriodTarget $target): void
    {
        $actual = KpiActual::query()->where('period_target_id', $target->id)->firstOrFail();

        app(EvidenceService::class)->attach(self::actor($this->scorecards->staff), $actual->id, 'laporan-mutu.pdf', (string) file_get_contents(self::evidencePdf()));
    }

    /**
     * Visits a page and waits until its content is there. Firefox can hand
     * back the page before the navigation commits; a script or an axe scan
     * run then would see about:blank or lose its context mid-navigation.
     * Locator assertions retry, so this wait is safe on every browser.
     */
    public static function open(string $url, string $ready = '#main-content'): mixed
    {
        return visit($url)->assertPresent($ready);
    }

    /**
     * Livewire `wire:confirm` uses window.confirm; Playwright dismisses
     * dialogs unless told otherwise. Re-applied after every visit.
     */
    public static function acceptConfirmations(mixed $page): void
    {
        $page->script('window.confirm = () => true');
    }

    /**
     * HR enters and submits the actuals the journey does not type in:
     * W-002 quality 90, team 110, DSO 80.
     */
    public function submitOtherActuals(): void
    {
        $scenario = $this->scorecards;
        $scenario->enter($scenario->target('SC-QUALITY', 'W-002'), '90');
        $scenario->enter($scenario->target('SC-TEAM', 'PROD-WOVEN'), '110');
        $scenario->enter($scenario->target('SC-DSO', 'AR-001'), '80');

        app(ActualService::class)->submit($scenario->hrActor(), $scenario->period->id, ActualAccess::everything());
    }

    /**
     * AR-001 is outside the Woven team leader: verified by another account
     * than its submitter through the service.
     */
    public function verifyAccounting(): void
    {
        $leader = AccessFixtures::userWithRole(Role::TeamLeader, 'ACC-AR');

        app(ActualService::class)->verify(self::actor($leader), $this->scorecards->period->id, ActualAccess::everything());
    }

    public function review(string $subjectCode): ScorecardReview
    {
        return ScorecardReview::query()
            ->where('period_assignment_id', $this->scorecards->assignment($subjectCode)->id)
            ->firstOrFail();
    }

    /**
     * W-002 and PROD-WOVEN through the full chain; AR-001 (no reviewer)
     * through a registered bypass, calibration, and final approval.
     */
    public function approveOtherScorecards(): void
    {
        $hr = $this->scorecards->hr();
        app(BypassService::class)->register(self::actor($hr), $hr, AccessFixtures::unit('ACC-AR')->id, 'TEST-ONLY sign-off bypass', 'Team tanpa reviewer', AccessFixtures::TODAY, null);

        foreach (['W-002', 'PROD-WOVEN'] as $subject) {
            $this->decide('approve', $this->manager, $subject);
            $this->decide('calibrate', $hr, $subject);
            $this->decide('approve', $this->management, $subject);
        }

        $this->decide('calibrate', $hr, 'AR-001');
        $this->decide('approve', $this->management, 'AR-001');
    }

    public static function actor(User $user): Actor
    {
        return new Actor($user->id, Actor::system('e2e')->audit);
    }

    private function decide(string $decision, User $user, string $subject): void
    {
        $review = $this->review($subject);
        $workflow = app(WorkflowService::class);

        if ($decision === 'calibrate') {
            $workflow->calibrate(self::actor($user), $user, $review->id, $review->lock_version, null, null);

            return;
        }

        $workflow->approve(self::actor($user), $user, $review->id, $review->lock_version, null);
    }
}
