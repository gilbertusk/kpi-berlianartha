<?php

declare(strict_types=1);

namespace App\Modules\TeamReview\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CoachingNote;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Coaching notes are restricted personal data (DEC-011). The audit entry
 * records that a note was written, about whom, and by whom, never its text.
 */
final readonly class CoachingNoteService
{
    use GuardsInput;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private AccessControl $access,
        private OrganizationDirectory $directory,
        private TeamReviewAccess $reviewAccess,
    ) {}

    public function add(Actor $actor, User $user, int $employeeId, string $note): CoachingNote
    {
        $note = self::requireText($note, 'note', 'Catatan coaching wajib diisi.', 4000);

        return $this->transactions->run(function () use ($actor, $user, $employeeId, $note): CoachingNote {
            $employee = Employee::query()->findOrFail($employeeId);
            $today = $this->access->businessToday();
            $unitId = $this->directory->homeUnitId($employee->id, $today);

            if ($unitId === null || ! $this->reviewAccess->canWriteCoachingNote($user, $unitId)) {
                throw new AuthorizationException;
            }

            if ($employee->user_id === $user->id) {
                throw new BusinessRuleViolation('self_note', 'Anda tidak dapat menulis catatan coaching tentang diri sendiri.');
            }

            $entry = CoachingNote::query()->create([
                'employee_id' => $employee->id,
                'organization_unit_id' => $unitId,
                'author_user_id' => $user->id,
                'noted_on' => $today,
                'note' => $note,
            ]);

            $this->audit->record(new AuditEntry(
                action: 'coaching_note.added',
                entityType: 'coaching_note',
                entityId: $entry->public_id,
                before: null,
                after: ['employee' => $employee->public_id, 'noted_on' => $today],
                reason: null,
                context: $actor->audit,
            ));

            return $entry;
        });
    }
}
