<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application;

use App\Models\User;
use App\Modules\ActualEvidence\Application\ActualService;
use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Domain\EntryKind;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\Grant;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Scoring\Application\ScoringService;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Outbox\OutboxEvent;
use App\Modules\Shared\Application\Outbox\OutboxPublisher;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\Exceptions\ConcurrencyConflict;
use App\Modules\Workflow\Domain\CorrectionStatus;
use App\Modules\Workflow\Infrastructure\Persistence\Models\CorrectionRequest;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use LogicException;

/**
 * Corrections of verified actuals once a period is in REVIEW, FINALIZED, or
 * LOCKED. This is the only way such data changes, and the only "reopen" the
 * system has: the period itself never moves backwards.
 *
 * A correction needs two people: the requester, and an approver who is not
 * the requester and not the assessed employee. Approval writes the new
 * actual version, recalculates the period, and repoints the affected
 * scorecard reviews, all in one transaction; if any part fails nothing
 * changes. Who may request and approve is a provisional default
 * (HR requests, Management approves) until DEC-002 settles it.
 */
final readonly class CorrectionService
{
    use GuardsInput;

    private const CORRECTABLE = [PeriodStatus::Review, PeriodStatus::Finalized, PeriodStatus::Locked];

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OutboxPublisher $outbox,
        private OptimisticLock $lock,
        private AccessControl $access,
        private ActualService $actuals,
        private ScoringService $scoring,
        private WorkflowService $workflow,
    ) {}

    /**
     * @param  string|null  $value  null or empty = correct to N/A (the note carries why)
     */
    public function request(Actor $actor, User $user, int $actualId, ?string $value, ?string $note, string $reason, ?string $evidenceReference = null): CorrectionRequest
    {
        self::assertSameAccount($actor, $user);
        $reason = self::requireText($reason, 'reason', 'Alasan koreksi wajib diisi.');
        $note = self::optionalText($note, 1000);
        $evidenceReference = self::optionalText($evidenceReference, 255);

        try {
            return $this->transactions->run(function () use ($actor, $user, $actualId, $value, $note, $reason, $evidenceReference): CorrectionRequest {
                $actual = KpiActual::query()->with(['target.definitionVersion', 'target.employee'])->lockForUpdate()->findOrFail($actualId);
                $period = AssessmentPeriod::query()->sharedLock()->findOrFail($actual->target->assessment_period_id);
                $grant = $this->assertMay($user, Capability::CorrectionRequest, $actual);

                if (! in_array($period->status, self::CORRECTABLE, true)) {
                    throw new BusinessRuleViolation('correction_not_applicable', 'Koreksi berlaku pada periode Review, Finalized, atau Locked. Pada periode In progress aktual diperbaiki lewat alur pengisian biasa.');
                }

                $current = self::latestVersion($actual->id);

                if ($current->status !== ActualStatus::Verified) {
                    throw new BusinessRuleViolation('correction_not_applicable', 'Hanya aktual terverifikasi yang dapat dikoreksi.');
                }

                [$kind, $parsed] = self::proposed($actual, $value, $note);

                if ($current->entry_kind === $kind && self::sameValue($current->actual_value, $parsed)) {
                    throw new BusinessRuleViolation('no_change', 'Nilai koreksi sama dengan nilai yang berlaku.');
                }

                if (CorrectionRequest::query()->where('kpi_actual_id', $actual->id)->where('status', CorrectionStatus::Pending->value)->exists()) {
                    throw new BusinessRuleViolation('correction_pending', 'Aktual ini masih memiliki permintaan koreksi yang menunggu keputusan.');
                }

                $request = CorrectionRequest::query()->create([
                    'assessment_period_id' => $period->id,
                    'kpi_actual_id' => $actual->id,
                    'current_version_id' => $current->id,
                    'proposed_entry_kind' => $kind,
                    'proposed_value' => $parsed,
                    'proposed_note' => $note,
                    'reason' => $reason,
                    'evidence_reference' => $evidenceReference,
                    'status' => CorrectionStatus::Pending,
                    'requested_by_user_id' => $user->id,
                    'requested_at' => CarbonImmutable::now(),
                ]);

                $this->audit->record(new AuditEntry(
                    action: 'correction.requested',
                    entityType: 'correction_request',
                    entityId: $request->public_id,
                    before: ['actual' => $actual->public_id, 'version' => $current->version_number, 'value' => $current->displayValue()],
                    after: ['value' => $request->proposedDisplay(), 'period_status' => $period->status->value, 'evidence_reference' => $evidenceReference, ...self::authorizedBy($grant)],
                    reason: $reason,
                    context: $actor->audit,
                ));

                $this->outbox->publish(new OutboxEvent(
                    eventType: 'CorrectionRequested',
                    eventVersion: 1,
                    aggregateType: 'correction_request',
                    aggregateId: $request->public_id,
                    payload: ['period' => $period->public_id, 'actual' => $actual->public_id, 'period_status' => $period->status->value],
                    deduplicationKey: "correction-requested:{$request->public_id}",
                ));

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleViolation('correction_pending', 'Aktual ini masih memiliki permintaan koreksi yang menunggu keputusan.');
        }
    }

    public function approve(Actor $actor, User $user, int $requestId, int $expectedVersion, ?string $comment = null): CorrectionRequest
    {
        self::assertSameAccount($actor, $user);
        $comment = self::optionalText($comment, 1000);

        return $this->transactions->run(function () use ($actor, $user, $requestId, $expectedVersion, $comment): CorrectionRequest {
            [$request, $actual, $period] = $this->pending($requestId);
            $grant = $this->assertMay($user, Capability::CorrectionApprove, $actual);

            if ($request->requested_by_user_id === $user->id) {
                throw new BusinessRuleViolation('two_person_rule', 'Koreksi tidak dapat disetujui oleh orang yang memintanya.');
            }

            // A stale page is refused before any work is done.
            if ($request->lock_version !== $expectedVersion) {
                throw new ConcurrencyConflict($request->getMorphClass(), $expectedVersion);
            }

            if (! in_array($period->status, self::CORRECTABLE, true)) {
                throw new BusinessRuleViolation('correction_not_applicable', 'Periode ini tidak lagi berada pada status yang dapat dikoreksi.');
            }

            if (self::latestVersion($actual->id)->id !== $request->current_version_id) {
                throw new BusinessRuleViolation('correction_stale', 'Aktual ini sudah berubah sejak koreksi diminta. Tolak permintaan ini dan ajukan koreksi baru.');
            }

            $version = $this->actuals->applyCorrection(
                $request->requested_by_user_id,
                $actor,
                $actual->id,
                $request->proposed_entry_kind === EntryKind::NotApplicable ? null : $request->proposed_value,
                $request->proposed_note,
                $request->reason,
            );

            $summary = $this->scoring->recalculateForCorrection($actor, $period->id, "Koreksi {$request->public_id}: {$request->reason}");
            $affected = $this->workflow->syncAfterCorrection($actor, $period, $request->public_id, $request->reason, $grant->role->value);

            $request = $this->lock->update($request, $expectedVersion, [
                'status' => CorrectionStatus::Approved->value,
                'decided_by_user_id' => $user->id,
                'decided_at' => CarbonImmutable::now(),
                'decision_comment' => $comment,
                'new_version_id' => $version->id,
            ]);

            $this->audit->record(new AuditEntry(
                action: 'correction.approved',
                entityType: 'correction_request',
                entityId: $request->public_id,
                before: ['status' => CorrectionStatus::Pending->value],
                after: ['status' => $request->status->value, 'new_version' => $version->version_number, 'value' => $version->displayValue(), 'recalculated' => $summary['created'], 'scorecards_changed' => $affected, 'period_status' => $period->status->value, ...self::authorizedBy($grant)],
                reason: $comment ?? $request->reason,
                context: $actor->audit,
            ));

            $this->outbox->publish(new OutboxEvent(
                eventType: 'CorrectionApproved',
                eventVersion: 1,
                aggregateType: 'correction_request',
                aggregateId: $request->public_id,
                payload: ['period' => $period->public_id, 'actual' => $actual->public_id, 'period_status' => $period->status->value, 'scorecards_changed' => $affected],
                deduplicationKey: "correction-approved:{$request->public_id}",
            ));

            return $request;
        });
    }

    public function reject(Actor $actor, User $user, int $requestId, int $expectedVersion, string $comment): CorrectionRequest
    {
        self::assertSameAccount($actor, $user);
        $comment = self::requireText($comment, 'comment', 'Alasan penolakan wajib diisi.', 1000);

        return $this->transactions->run(function () use ($actor, $user, $requestId, $expectedVersion, $comment): CorrectionRequest {
            [$request, $actual] = $this->pending($requestId);
            $this->assertMay($user, Capability::CorrectionApprove, $actual);

            if ($request->requested_by_user_id === $user->id) {
                throw new BusinessRuleViolation('two_person_rule', 'Permintaan Anda sendiri dibatalkan, bukan ditolak.');
            }

            return $this->close($actor, $user, $request, $expectedVersion, CorrectionStatus::Rejected, $comment);
        });
    }

    /**
     * The requester withdraws a request that has not been decided.
     */
    public function cancel(Actor $actor, User $user, int $requestId, int $expectedVersion, string $comment): CorrectionRequest
    {
        self::assertSameAccount($actor, $user);
        $comment = self::requireText($comment, 'comment', 'Alasan pembatalan wajib diisi.', 1000);

        return $this->transactions->run(function () use ($actor, $user, $requestId, $expectedVersion, $comment): CorrectionRequest {
            [$request] = $this->pending($requestId);

            if ($request->requested_by_user_id !== $user->id) {
                throw new AuthorizationException;
            }

            return $this->close($actor, $user, $request, $expectedVersion, CorrectionStatus::Cancelled, $comment);
        });
    }

    /**
     * @return array{0: EntryKind, 1: string|null}
     */
    private static function proposed(KpiActual $actual, ?string $value, ?string $note): array
    {
        if ($value === null || trim($value) === '') {
            if ($note === null) {
                throw new BusinessRuleViolation('note_required', 'Koreksi menjadi N/A wajib disertai catatan alasan.');
            }

            return [EntryKind::NotApplicable, null];
        }

        try {
            return [EntryKind::Value, $actual->target->valueRules()->named('aktual')->parse($value, (string) $actual->target->definitionVersion->unit)];
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRuleViolation('invalid_value', $exception->getMessage());
        }
    }

    private static function sameValue(?string $current, ?string $proposed): bool
    {
        if ($current === null || $proposed === null) {
            return $current === $proposed;
        }

        return BigDecimal::of($current)->isEqualTo($proposed);
    }

    private static function latestVersion(int $actualId): KpiActualVersion
    {
        return KpiActualVersion::query()->where('kpi_actual_id', $actualId)->orderByDesc('version_number')->firstOrFail();
    }

    /**
     * @return array{0: CorrectionRequest, 1: KpiActual, 2: AssessmentPeriod}
     */
    private function pending(int $requestId): array
    {
        $request = CorrectionRequest::query()->lockForUpdate()->findOrFail($requestId);

        if ($request->status !== CorrectionStatus::Pending) {
            throw new BusinessRuleViolation('correction_decided', 'Permintaan koreksi ini sudah diputuskan.');
        }

        $actual = KpiActual::query()->with(['target.definitionVersion', 'target.employee'])->lockForUpdate()->findOrFail($request->kpi_actual_id);
        $period = AssessmentPeriod::query()->sharedLock()->findOrFail($request->assessment_period_id);

        return [$request, $actual, $period];
    }

    /**
     * The capability must cover the unit of the corrected KPI, and nobody
     * requests or approves a correction of their own result.
     */
    private function assertMay(User $user, Capability $capability, KpiActual $actual): Grant
    {
        $grant = $this->access->decide($user, $capability, $this->access->unitTarget($actual->target->organization_unit_id))->grant;

        if ($grant === null) {
            throw new AuthorizationException;
        }

        if ($actual->target->employee?->user_id === $user->id) {
            throw new BusinessRuleViolation('self_action', 'Anda tidak dapat meminta atau memutuskan koreksi atas hasil Anda sendiri.');
        }

        return $grant;
    }

    /**
     * Which role and grant authorized a correction action, for the audit trail.
     *
     * @return array{authorized_role: string, authorized_grant: string}
     */
    private static function authorizedBy(Grant $grant): array
    {
        return ['authorized_role' => $grant->role->value, 'authorized_grant' => "{$grant->source}:{$grant->sourcePublicId}"];
    }

    private function close(Actor $actor, User $user, CorrectionRequest $request, int $expectedVersion, CorrectionStatus $status, string $comment): CorrectionRequest
    {
        $request = $this->lock->update($request, $expectedVersion, [
            'status' => $status->value,
            'decided_by_user_id' => $user->id,
            'decided_at' => CarbonImmutable::now(),
            'decision_comment' => $comment,
        ]);

        $this->audit->record(new AuditEntry(
            action: $status === CorrectionStatus::Rejected ? 'correction.rejected' : 'correction.cancelled',
            entityType: 'correction_request',
            entityId: $request->public_id,
            before: ['status' => CorrectionStatus::Pending->value],
            after: ['status' => $status->value],
            reason: $comment,
            context: $actor->audit,
        ));

        return $request;
    }

    private static function assertSameAccount(Actor $actor, User $user): void
    {
        if ($actor->userId !== $user->id) {
            throw new LogicException('The audit actor and the deciding account must be the same.');
        }
    }
}
