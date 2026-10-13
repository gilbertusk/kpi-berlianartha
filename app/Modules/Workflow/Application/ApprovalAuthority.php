<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\Grant;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignmentApprover;

/**
 * Decides who may act at one approval stage of one scorecard. All of these
 * must hold, evaluated at the moment of the action:
 *
 * 1. the account holds `approval.decide` through a grant whose role is the
 *    role of the stage and whose scope covers the subject's unit;
 * 2. the account is an approver of that stage in the snapshot taken when the
 *    period was opened, or acts through an active delegation received from
 *    such an approver (an expired or revoked delegation is no grant at all);
 * 3. the account is not the assessed employee and does not act for the
 *    assessed employee (PRD §4.1: no self-approval).
 *
 * Who holds a stage role for a team is configuration (DEC-002); nothing here
 * fills a gap by assumption.
 */
final readonly class ApprovalAuthority
{
    public function __construct(
        private AccessControl $access,
        private OrganizationDirectory $directory,
    ) {}

    public function resolve(User $user, PeriodAssignment $assignment, ApprovalStage $stage): ?StageAuthority
    {
        $subjectUserId = $assignment->employee?->user_id;

        if ($subjectUserId !== null && $subjectUserId === $user->id) {
            return null;
        }

        $role = Role::from($stage->roleKey());
        $approverIds = $this->snapshotApproverIds($assignment, $stage);

        if ($approverIds === []) {
            return null;
        }

        $grants = $this->access->grants($user);
        $snapshot = $this->directory->snapshot($grants->businessDate);

        foreach ($grants->withCapability(Capability::ApprovalDecide) as $grant) {
            if ($grant->role !== $role || ! $grant->coversUnit($assignment->organization_unit_id, $snapshot)) {
                continue;
            }

            if ($grant->source === Grant::SOURCE_ASSIGNMENT) {
                if (in_array($user->id, $approverIds, true)) {
                    return new StageAuthority($stage, $grant, null);
                }

                continue;
            }

            $delegator = $grant->delegatorUserId;

            if ($delegator !== null && $delegator !== $subjectUserId && in_array($delegator, $approverIds, true)) {
                return new StageAuthority($stage, $grant, $delegator);
            }
        }

        return null;
    }

    public function holdsAnyStage(User $user, PeriodAssignment $assignment): bool
    {
        foreach ([ApprovalStage::Review, ApprovalStage::Calibration, ApprovalStage::FinalApproval] as $stage) {
            if ($this->resolve($user, $assignment, $stage) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function snapshotApproverIds(PeriodAssignment $assignment, ApprovalStage $stage): array
    {
        return array_values($assignment->approvers
            ->filter(static fn (PeriodAssignmentApprover $approver): bool => $approver->stage === $stage)
            ->map(static fn (PeriodAssignmentApprover $approver): int => $approver->user_id)
            ->all());
    }
}
