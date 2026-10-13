<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\PeriodAssignment\Application\PeriodVisibility;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Scoring\Application\ScoreAccess;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;

/**
 * Who may see the approval workflow. A scorecard's review is visible to
 * accounts whose scorecard scope covers the subject (not merely as the
 * assessed employee) and to the accounts that may decide one of its stages:
 * approving a score includes reading it.
 */
final readonly class WorkflowAccess
{
    /**
     * Capabilities that give the Approval menu a purpose. Accounts that may
     * read individual scorecards (HR, Auditor) may read the workflow too.
     */
    public const CAPABILITIES = [
        Capability::ApprovalDecide,
        Capability::IndividualScorecardView,
        Capability::ScorecardCalculate,
        Capability::CorrectionRequest,
        Capability::CorrectionApprove,
        Capability::ApprovalRouteManage,
    ];

    public function __construct(
        private AccessControl $access,
        private PeriodVisibility $visibility,
        private ApprovalAuthority $authority,
    ) {}

    /**
     * Scorecards in scope, without the "own scorecard" exception: an
     * employee does not read the approval notes about themselves here.
     */
    public function boardScope(User $user): ScoreAccess
    {
        $scope = ScoreAccess::forViewing($this->access, $user);

        return new ScoreAccess($scope->units, $scope->individuals, null);
    }

    public function canViewPeriod(User $user, AssessmentPeriod $period): bool
    {
        if (! $this->access->allowsAny($user, self::CAPABILITIES)) {
            return false;
        }

        $today = $this->access->businessToday();

        foreach ([Capability::ScorecardView, Capability::ApprovalDecide, Capability::CorrectionApprove] as $capability) {
            if ($this->visibility->concerns($this->access->unitScope($user, $capability), $period, $today)) {
                return true;
            }
        }

        return false;
    }

    public function canViewReview(User $user, ScorecardReview $review): bool
    {
        $assignment = PeriodAssignment::query()->with(['approvers', 'employee'])->findOrFail($review->period_assignment_id);

        return $this->covers($user, $assignment, $this->boardScope($user));
    }

    public function covers(User $user, PeriodAssignment $assignment, ScoreAccess $scope): bool
    {
        if ($assignment->employee?->user_id === $user->id) {
            return false;
        }

        return $scope->covers($assignment) || $this->authority->holdsAnyStage($user, $assignment);
    }

    /**
     * Units on which the account may request or approve corrections.
     */
    public function correctionScope(User $user): UnitScope
    {
        $request = $this->access->unitScope($user, Capability::CorrectionRequest);
        $approve = $this->access->unitScope($user, Capability::CorrectionApprove);

        return new UnitScope($request->all || $approve->all, array_values(array_unique([...$request->unitIds, ...$approve->unitIds])), false);
    }

    public function mayRequestCorrection(User $user, int $unitId): bool
    {
        return $this->access->allows($user, Capability::CorrectionRequest, $this->access->unitTarget($unitId));
    }

    public function mayApproveCorrection(User $user, int $unitId): bool
    {
        return $this->access->allows($user, Capability::CorrectionApprove, $this->access->unitTarget($unitId));
    }

    public function mayResubmit(User $user, PeriodAssignment $assignment): bool
    {
        return $assignment->employee?->user_id !== $user->id
            && $this->access->allows($user, Capability::ScorecardCalculate, $this->access->unitTarget($assignment->organization_unit_id));
    }
}
