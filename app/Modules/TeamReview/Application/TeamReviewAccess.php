<?php

declare(strict_types=1);

namespace App\Modules\TeamReview\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CoachingNote;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CorrectiveAction;

/**
 * Who may see and do what in the team review.
 *
 * - Issues and actions: readable in the `team_review.view` scope; an owner
 *   (PIC) always reads their own action.
 * - Recording issues and assigning, verifying, closing, or rescheduling
 *   actions: `team_review.manage` over the unit.
 * - Coaching notes (DEC-011, not approved): the narrowest reading of the
 *   proposal. Only the author and accounts holding `coaching_note.manage`
 *   over the unit the note was written for may read a note; never the
 *   subject, and no other role until DEC-011 says so.
 */
final readonly class TeamReviewAccess
{
    public function __construct(private AccessControl $access) {}

    public function viewScope(User $user): UnitScope
    {
        return $this->access->unitScope($user, Capability::TeamReviewView);
    }

    public function manageScope(User $user): UnitScope
    {
        return $this->access->unitScope($user, Capability::TeamReviewManage);
    }

    public function coachingScope(User $user): UnitScope
    {
        return $this->access->unitScope($user, Capability::CoachingNoteManage);
    }

    public function canUse(User $user): bool
    {
        return $this->access->allows($user, Capability::TeamReviewView)
            || CorrectiveAction::query()->where('owner_user_id', $user->id)->exists();
    }

    public function canManage(User $user, int $unitId): bool
    {
        return $this->access->allows($user, Capability::TeamReviewManage, $this->access->unitTarget($unitId));
    }

    public function canViewAction(User $user, CorrectiveAction $action): bool
    {
        return $action->owner_user_id === $user->id || $this->viewScope($user)->coversUnit($action->organization_unit_id);
    }

    public function canWriteCoachingNote(User $user, int $unitId): bool
    {
        return $this->access->allows($user, Capability::CoachingNoteManage, $this->access->unitTarget($unitId));
    }

    public function canReadCoachingNote(User $user, CoachingNote $note): bool
    {
        if ($note->employee->user_id === $user->id) {
            return false;
        }

        return $note->author_user_id === $user->id || $this->canWriteCoachingNote($user, $note->organization_unit_id);
    }
}
