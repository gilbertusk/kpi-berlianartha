<?php

declare(strict_types=1);

namespace App\Modules\TeamReview\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Outbox\OutboxEvent;
use App\Modules\Shared\Application\Outbox\OutboxPublisher;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\StateTransitionGuard;
use App\Modules\TeamReview\Domain\ActionPriority;
use App\Modules\TeamReview\Domain\ActionStatus;
use App\Modules\TeamReview\Domain\IssueContext;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CorrectiveAction;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\CorrectiveActionEvent;
use App\Modules\TeamReview\Infrastructure\Persistence\Models\TeamReviewIssue;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Daily team review and corrective actions (PRD §9.2, FR 013).
 *
 * An action always has an owner and a due date. The owner moves it forward
 * and reports completion; an account that manages the team and is not the
 * owner verifies and closes it. Every step is written to the action's
 * history and to the audit log.
 */
final readonly class TeamReviewService
{
    use GuardsInput;

    private const MAX_KPIS = 20;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OutboxPublisher $outbox,
        private OptimisticLock $lock,
        private StateTransitionGuard $transitions,
        private AccessControl $access,
        private TeamReviewAccess $reviewAccess,
        private TeamReviewQuery $query,
        private bool $completionEvidenceRequired,
    ) {}

    public function recordIssue(Actor $actor, User $user, IssueDraft $draft): TeamReviewIssue
    {
        self::assertSameAccount($actor, $user);
        $problem = self::requireText($draft->problem, 'problem', 'Masalah wajib diisi.');
        $causeNote = self::optionalText($draft->causeNote);
        $causeCode = self::optionalText($draft->causeCode, 60);
        $contextReference = self::optionalText($draft->contextReference, 120);
        self::requirePeriod($draft->reviewDate);

        if ($draft->reviewDate > $this->access->businessToday()) {
            throw new BusinessRuleViolation('review_date_in_future', 'Tanggal review tidak boleh di masa depan.');
        }

        if ($causeCode !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,59}$/', $causeCode) !== 1) {
            throw new BusinessRuleViolation('invalid_cause_code', 'Kode penyebab hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.');
        }

        $context = $draft->contextType === null || $draft->contextType === '' ? null
            : (IssueContext::tryFrom($draft->contextType) ?? throw new BusinessRuleViolation('invalid_context', 'Jenis konteks masalah tidak dikenal.'));

        if (($context === null) !== ($contextReference === null)) {
            throw new BusinessRuleViolation('context_incomplete', 'Jenis konteks dan referensinya harus diisi bersama.');
        }

        if (count($draft->kpiDefinitionIds) > self::MAX_KPIS) {
            throw new BusinessRuleViolation('too_many_kpis', 'Maksimal '.self::MAX_KPIS.' KPI terdampak per masalah.');
        }

        return $this->transactions->run(function () use ($actor, $user, $draft, $problem, $causeCode, $causeNote, $context, $contextReference): TeamReviewIssue {
            $unit = OrganizationUnit::query()->findOrFail($draft->unitId);
            $this->assertManages($user, $unit->id);

            $kpis = KpiDefinition::query()->whereIn('public_id', array_values(array_unique($draft->kpiDefinitionIds)))->get();

            if ($kpis->count() !== count(array_unique($draft->kpiDefinitionIds))) {
                throw new BusinessRuleViolation('kpi_not_found', 'Salah satu KPI terdampak tidak ditemukan.');
            }

            $issue = TeamReviewIssue::query()->create([
                'organization_unit_id' => $unit->id,
                'review_date' => $draft->reviewDate,
                'problem' => $problem,
                'cause_code' => $causeCode === null ? null : strtoupper($causeCode),
                'cause_note' => $causeNote,
                'context_type' => $context,
                'context_reference' => $contextReference,
                'reported_by_user_id' => $user->id,
            ]);
            $issue->kpis()->attach($kpis->modelKeys());

            $this->audit->record(new AuditEntry(
                action: 'team_review.issue_recorded',
                entityType: 'team_review_issue',
                entityId: $issue->public_id,
                before: null,
                after: ['unit' => $unit->code, 'review_date' => $draft->reviewDate, 'cause_code' => $issue->cause_code, 'kpis' => $kpis->pluck('code')->sort()->values()->all()],
                reason: null,
                context: $actor->audit,
            ));

            return $issue;
        });
    }

    /**
     * Adds an action in status OPEN. PIC and due date are mandatory.
     */
    public function assignAction(Actor $actor, User $user, int $issueId, ActionDraft $draft): CorrectiveAction
    {
        self::assertSameAccount($actor, $user);
        $text = self::requireText($draft->action, 'action', 'Tindakan wajib diisi.');
        $priority = ActionPriority::tryFrom($draft->priority) ?? throw new BusinessRuleViolation('invalid_priority', 'Prioritas tidak dikenal.');
        self::requirePeriod($draft->dueOn);

        return $this->transactions->run(function () use ($actor, $user, $issueId, $draft, $text, $priority): CorrectiveAction {
            $issue = TeamReviewIssue::query()->lockForUpdate()->findOrFail($issueId);
            $this->assertManages($user, $issue->organization_unit_id);

            if ($draft->dueOn < $this->access->businessToday()) {
                throw new BusinessRuleViolation('due_date_in_past', 'Tenggat tidak boleh sebelum hari ini.');
            }

            if (! array_key_exists($draft->ownerUserId, $this->query->ownerCandidates($issue->organization_unit_id))) {
                throw new BusinessRuleViolation('invalid_owner', 'PIC harus anggota unit ini yang memiliki akun aktif, atau pengelola review unit ini.');
            }

            $action = CorrectiveAction::query()->create([
                'team_review_issue_id' => $issue->id,
                'organization_unit_id' => $issue->organization_unit_id,
                'action' => $text,
                'owner_user_id' => $draft->ownerUserId,
                'due_on' => $draft->dueOn,
                'priority' => $priority,
                'status' => ActionStatus::Open,
                'created_by_user_id' => $user->id,
            ]);

            $this->history($action, 'created', null, $user, null);
            $this->record($actor, $action, 'corrective_action.assigned', null, ['status' => ActionStatus::Open->value, 'owner' => $action->owner->public_id, 'due_on' => $action->due_on, 'priority' => $priority->value], null);
            $this->announce($action, 'CorrectiveActionAssigned', '1');

            return $action;
        });
    }

    public function start(Actor $actor, User $user, int $actionId, int $expectedVersion): CorrectiveAction
    {
        return $this->ownerMove($actor, $user, $actionId, $expectedVersion, ActionStatus::InProgress, null, ['waiting_reason' => null]);
    }

    public function wait(Actor $actor, User $user, int $actionId, int $expectedVersion, string $reason): CorrectiveAction
    {
        $reason = self::requireText($reason, 'reason', 'Alasan menunggu wajib diisi.', 1000);

        return $this->ownerMove($actor, $user, $actionId, $expectedVersion, ActionStatus::Waiting, $reason, ['waiting_reason' => $reason]);
    }

    public function resume(Actor $actor, User $user, int $actionId, int $expectedVersion): CorrectiveAction
    {
        return $this->ownerMove($actor, $user, $actionId, $expectedVersion, ActionStatus::InProgress, null, ['waiting_reason' => null]);
    }

    /**
     * The owner reports the action done. It stays IN_PROGRESS until someone
     * else verifies it.
     */
    public function reportCompletion(Actor $actor, User $user, int $actionId, int $expectedVersion, string $note, ?string $evidenceReference): CorrectiveAction
    {
        self::assertSameAccount($actor, $user);
        $note = self::requireText($note, 'completion_note', 'Catatan penyelesaian wajib diisi.');
        $evidenceReference = self::optionalText($evidenceReference, 255);

        if ($this->completionEvidenceRequired && $evidenceReference === null) {
            throw new BusinessRuleViolation('evidence_required', 'Bukti penyelesaian wajib diisi sebelum tindakan diverifikasi.');
        }

        return $this->transactions->run(function () use ($actor, $user, $actionId, $expectedVersion, $note, $evidenceReference): CorrectiveAction {
            $action = $this->ownedBy($user, $actionId);

            if ($action->status !== ActionStatus::InProgress) {
                throw new BusinessRuleViolation('action_not_in_progress', 'Penyelesaian hanya dapat dilaporkan untuk tindakan berstatus In progress.');
            }

            if ($action->completed_at !== null) {
                throw new BusinessRuleViolation('completion_reported', 'Penyelesaian sudah dilaporkan dan menunggu verifikasi.');
            }

            $action = $this->lock->update($action, $expectedVersion, [
                'completion_note' => $note,
                'evidence_reference' => $evidenceReference,
                'completed_at' => CarbonImmutable::now(),
            ]);

            $this->history($action, 'completion_reported', ActionStatus::InProgress, $user, $note);
            $this->record($actor, $action, 'corrective_action.completion_reported', null, ['evidence_reference' => $evidenceReference], $note);
            $this->announce($action, 'CorrectiveActionCompleted', (string) $action->lock_version);

            return $action;
        });
    }

    /**
     * A manager of the team who is not the owner confirms the completion.
     */
    public function verify(Actor $actor, User $user, int $actionId, int $expectedVersion, ?string $note = null): CorrectiveAction
    {
        self::assertSameAccount($actor, $user);
        $note = self::optionalText($note, 1000);

        return $this->transactions->run(function () use ($actor, $user, $actionId, $expectedVersion, $note): CorrectiveAction {
            $action = $this->managedBy($user, $actionId);

            if ($action->owner_user_id === $user->id) {
                throw new BusinessRuleViolation('self_verification', 'Tindakan Anda sendiri harus diverifikasi oleh orang lain.');
            }

            if (! $action->awaitsVerification()) {
                throw new BusinessRuleViolation('completion_not_reported', 'PIC belum melaporkan penyelesaian tindakan ini.');
            }

            if ($this->completionEvidenceRequired && $action->evidence_reference === null) {
                throw new BusinessRuleViolation('evidence_required', 'Bukti penyelesaian wajib ada sebelum tindakan diverifikasi.');
            }

            $this->transitions->assertAllowed($action->status->value, ActionStatus::Verified->value, ActionStatus::transitions());
            $action = $this->lock->update($action, $expectedVersion, [
                'status' => ActionStatus::Verified->value,
                'reviewer_user_id' => $user->id,
                'verified_at' => CarbonImmutable::now(),
            ]);

            $this->history($action, 'status_changed', ActionStatus::InProgress, $user, $note);
            $this->record($actor, $action, 'corrective_action.verified', ['status' => ActionStatus::InProgress->value], ['status' => ActionStatus::Verified->value], $note);

            return $action;
        });
    }

    /**
     * The completion is not accepted: the action stays IN_PROGRESS and the
     * owner reports again.
     */
    public function sendBack(Actor $actor, User $user, int $actionId, int $expectedVersion, string $reason): CorrectiveAction
    {
        self::assertSameAccount($actor, $user);
        $reason = self::requireText($reason, 'reason', 'Alasan pengembalian wajib diisi.', 1000);

        return $this->transactions->run(function () use ($actor, $user, $actionId, $expectedVersion, $reason): CorrectiveAction {
            $action = $this->managedBy($user, $actionId);

            if ($action->owner_user_id === $user->id || ! $action->awaitsVerification()) {
                throw new BusinessRuleViolation('completion_not_reported', 'Hanya penyelesaian yang dilaporkan orang lain yang dapat dikembalikan.');
            }

            $action = $this->lock->update($action, $expectedVersion, ['completed_at' => null]);
            $this->history($action, 'completion_returned', ActionStatus::InProgress, $user, $reason);
            $this->record($actor, $action, 'corrective_action.completion_returned', null, null, $reason);

            return $action;
        });
    }

    public function close(Actor $actor, User $user, int $actionId, int $expectedVersion): CorrectiveAction
    {
        self::assertSameAccount($actor, $user);

        return $this->transactions->run(function () use ($actor, $user, $actionId, $expectedVersion): CorrectiveAction {
            $action = $this->managedBy($user, $actionId);
            $this->transitions->assertAllowed($action->status->value, ActionStatus::Closed->value, ActionStatus::transitions());

            $action = $this->lock->update($action, $expectedVersion, [
                'status' => ActionStatus::Closed->value,
                'closed_by_user_id' => $user->id,
                'closed_at' => CarbonImmutable::now(),
            ]);

            $this->history($action, 'status_changed', ActionStatus::Verified, $user, null);
            $this->record($actor, $action, 'corrective_action.closed', ['status' => ActionStatus::Verified->value], ['status' => ActionStatus::Closed->value], null);

            return $action;
        });
    }

    /**
     * Moves the due date of an outstanding action, with a reason.
     */
    public function reschedule(Actor $actor, User $user, int $actionId, int $expectedVersion, string $dueOn, string $reason): CorrectiveAction
    {
        self::assertSameAccount($actor, $user);
        $reason = self::requireText($reason, 'reason', 'Alasan perubahan tenggat wajib diisi.', 1000);
        self::requirePeriod($dueOn);

        return $this->transactions->run(function () use ($actor, $user, $actionId, $expectedVersion, $dueOn, $reason): CorrectiveAction {
            $action = $this->managedBy($user, $actionId);

            if ($action->owner_user_id === $user->id) {
                throw new BusinessRuleViolation('self_reschedule', 'Tenggat tindakan Anda sendiri harus diubah oleh pengelola lain.');
            }

            if (! $action->status->isOutstanding()) {
                throw new BusinessRuleViolation('action_not_outstanding', 'Tenggat tindakan yang sudah diverifikasi atau ditutup tidak dapat diubah.');
            }

            if ($dueOn < $this->access->businessToday()) {
                throw new BusinessRuleViolation('due_date_in_past', 'Tenggat tidak boleh sebelum hari ini.');
            }

            if ($dueOn === $action->due_on) {
                throw new BusinessRuleViolation('no_change', 'Tenggat tidak berubah.');
            }

            $before = $action->due_on;
            $action = $this->lock->update($action, $expectedVersion, ['due_on' => $dueOn]);
            $this->history($action, 'rescheduled', $action->status, $user, "{$before} → {$dueOn}: {$reason}");
            $this->record($actor, $action, 'corrective_action.rescheduled', ['due_on' => $before], ['due_on' => $dueOn], $reason);

            return $action;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ownerMove(Actor $actor, User $user, int $actionId, int $expectedVersion, ActionStatus $to, ?string $note, array $attributes): CorrectiveAction
    {
        self::assertSameAccount($actor, $user);

        return $this->transactions->run(function () use ($actor, $user, $actionId, $expectedVersion, $to, $note, $attributes): CorrectiveAction {
            $action = $this->ownedBy($user, $actionId);
            $from = $action->status;
            $this->transitions->assertAllowed($from->value, $to->value, ActionStatus::transitions());

            if ($to === ActionStatus::Waiting && $action->completed_at !== null) {
                throw new BusinessRuleViolation('completion_reported', 'Tindakan yang penyelesaiannya sudah dilaporkan menunggu verifikasi.');
            }

            $action = $this->lock->update($action, $expectedVersion, ['status' => $to->value, ...$attributes]);
            $this->history($action, 'status_changed', $from, $user, $note);
            $this->record($actor, $action, 'corrective_action.status_changed', ['status' => $from->value], ['status' => $to->value], $note);

            return $action;
        });
    }

    private function ownedBy(User $user, int $actionId): CorrectiveAction
    {
        $action = CorrectiveAction::query()->lockForUpdate()->findOrFail($actionId);

        if ($action->owner_user_id !== $user->id) {
            throw new AuthorizationException;
        }

        return $action;
    }

    private function managedBy(User $user, int $actionId): CorrectiveAction
    {
        $action = CorrectiveAction::query()->lockForUpdate()->findOrFail($actionId);
        $this->assertManages($user, $action->organization_unit_id);

        return $action;
    }

    private function assertManages(User $user, int $unitId): void
    {
        if (! $this->reviewAccess->canManage($user, $unitId)) {
            throw new AuthorizationException;
        }
    }

    private function history(CorrectiveAction $action, string $event, ?ActionStatus $from, User $user, ?string $note): void
    {
        CorrectiveActionEvent::query()->create([
            'corrective_action_id' => $action->id,
            'event' => $event,
            'from_status' => $from?->value,
            'to_status' => $action->status->value,
            'note' => $note,
            'actor_user_id' => $user->id,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Actor $actor, CorrectiveAction $action, string $name, ?array $before, ?array $after, ?string $reason): void
    {
        $this->audit->record(new AuditEntry(
            action: $name,
            entityType: 'corrective_action',
            entityId: $action->public_id,
            before: $before,
            after: $after,
            reason: $reason,
            context: $actor->audit,
        ));
    }

    private function announce(CorrectiveAction $action, string $event, string $occasion): void
    {
        $this->outbox->publish(new OutboxEvent(
            eventType: $event,
            eventVersion: 1,
            aggregateType: 'corrective_action',
            aggregateId: $action->public_id,
            payload: ['occasion' => $occasion, 'due_on' => $action->due_on],
            deduplicationKey: "{$event}:{$action->public_id}:{$occasion}",
        ));
    }

    private static function assertSameAccount(Actor $actor, User $user): void
    {
        if ($actor->userId !== $user->id) {
            throw new LogicException('The audit actor and the acting account must be the same.');
        }
    }
}
