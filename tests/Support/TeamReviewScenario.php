<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\TeamReview\Application\ActionDraft;
use App\Modules\TeamReview\Application\IssueDraft;
use App\Modules\TeamReview\Application\TeamReviewService;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CorrectiveAction;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\TeamReviewIssue;

/**
 * PROD-WOVEN with a team leader, a department head (PROD), and two members:
 * "member" has an account, W-012 has none. A second team (ACC-AR) has its
 * own leader. No KPI period is needed for the team review itself.
 */
final class TeamReviewScenario
{
    public User $leader;

    public User $head;

    public User $member;

    public Employee $memberEmployee;

    public Employee $otherMember;

    public User $otherLeader;

    public User $hr;

    public User $management;

    public User $auditor;

    public function __construct()
    {
        AccessFixtures::seedStructure();

        $this->leader = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
        $this->head = AccessFixtures::userWithRole(Role::DepartmentHead, 'PROD');
        $this->otherLeader = AccessFixtures::userWithRole(Role::TeamLeader, 'ACC-AR');
        $this->hr = AccessFixtures::userWithRole(Role::HrPerformance, 'BAL');
        $this->management = AccessFixtures::userWithRole(Role::Management, 'BAL');
        $this->auditor = AccessFixtures::userWithRole(Role::Auditor, 'BAL');

        $this->member = AccessFixtures::user('Anggota Woven');
        $this->memberEmployee = AccessFixtures::employee($this->member, 'PROD-WOVEN', number: 'W-011');
        AccessFixtures::grant($this->member, Role::Employee);
        $this->otherMember = AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-012');
    }

    public static function actor(User $user): Actor
    {
        return new Actor($user->id, Actor::system('test')->audit);
    }

    /**
     * @param  array<string, mixed>  $overrides  IssueDraft arguments by name
     */
    public function issue(?User $by = null, array $overrides = []): TeamReviewIssue
    {
        $by ??= $this->leader;

        return app(TeamReviewService::class)->recordIssue(self::actor($by), $by, new IssueDraft(...[
            'unitId' => AccessFixtures::unit('PROD-WOVEN')->id,
            'reviewDate' => AccessFixtures::TODAY,
            'problem' => 'Benang putus berulang pada mesin 3',
            'causeCode' => 'mesin-setting',
            'causeNote' => 'Tension belum disetel ulang',
            ...$overrides,
        ]));
    }

    public function action(?TeamReviewIssue $issue = null, ?User $owner = null, string $dueOn = '2026-10-15', ?User $by = null): CorrectiveAction
    {
        $by ??= $this->leader;
        $issue ??= $this->issue($by);

        return app(TeamReviewService::class)->assignAction(self::actor($by), $by, $issue->id, new ActionDraft(
            action: 'Setel ulang tension dan catat hasilnya',
            ownerUserId: ($owner ?? $this->member)->id,
            dueOn: $dueOn,
            priority: 'high',
        ));
    }

    /**
     * Owner starts and reports completion.
     */
    public function completed(?CorrectiveAction $action = null, ?string $evidence = 'FOTO-001'): CorrectiveAction
    {
        $action ??= $this->action();
        $owner = User::query()->findOrFail($action->owner_user_id);
        $service = app(TeamReviewService::class);
        $action = $service->start(self::actor($owner), $owner, $action->id, $action->lock_version);

        return $service->reportCompletion(self::actor($owner), $owner, $action->id, $action->lock_version, 'Tension sudah disetel', $evidence);
    }
}
