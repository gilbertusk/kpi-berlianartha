<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Application;

use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\DeadlineStage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Domain\PeriodType;
use App\Modules\PeriodAssignment\Domain\SubjectType;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriodDeadline;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignmentApprover;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Concurrency\OptimisticLock;
use App\Modules\Shared\Application\Outbox\OutboxEvent;
use App\Modules\Shared\Application\Outbox\OutboxPublisher;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\StateTransitionGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Assessment period use cases. Opening a period stores the assignment and
 * approver snapshot in the same transaction as the status change
 * (DOMAIN_BOUNDARIES.md §4), so a period is never open without its snapshot.
 *
 * REVIEW, FINALIZED, and LOCKED are reached through {@see advance()}, gated by
 * the prechecks of the modules that depend on the period (Step 08–10).
 */
final readonly class PeriodService
{
    use GuardsInput;

    private const APPROVER_INSERT_CHUNK = 500;

    /**
     * @param  iterable<PeriodLifecycleParticipant>  $participants
     */
    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OutboxPublisher $outbox,
        private OptimisticLock $lock,
        private StateTransitionGuard $transitions,
        private OrganizationDirectory $directory,
        private BusinessTime $time,
        private AssignmentSnapshotBuilder $snapshots,
        private iterable $participants,
    ) {}

    public function createDraft(Actor $actor, PeriodSetup $setup): AssessmentPeriod
    {
        $valid = $this->validated($setup);

        return $this->transactions->run(function () use ($actor, $valid): AssessmentPeriod {
            $this->assertScopeUnit($valid['organization_unit_id'], $valid['assignment_reference_date']);
            $this->assertNoOverlap($valid, null, false);

            if (AssessmentPeriod::query()->where('code', $valid['code'])->exists()) {
                throw new BusinessRuleViolation('period_code_taken', 'Kode periode sudah digunakan.');
            }

            try {
                $period = AssessmentPeriod::query()->create([
                    ...self::attributes($valid),
                    'timezone' => $this->time->timezoneName(),
                    'status' => PeriodStatus::Draft,
                    'created_by_user_id' => $actor->userId,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessRuleViolation('period_duplicate', 'Periode dengan tipe, scope, dan rentang tanggal yang sama sudah ada.');
            }

            $this->storeDeadlines($period, $valid['deadlines']);
            $this->record($actor, $period, 'assessment_period.created', null, self::auditValues($valid), null);

            return $period->load('deadlines');
        });
    }

    public function updateDraft(Actor $actor, int $periodId, int $expectedVersion, PeriodSetup $setup): AssessmentPeriod
    {
        $valid = $this->validated($setup);

        return $this->transactions->run(function () use ($actor, $periodId, $expectedVersion, $valid): AssessmentPeriod {
            $period = AssessmentPeriod::query()->with('deadlines')->lockForUpdate()->findOrFail($periodId);

            if (! $period->status->isEditable()) {
                throw new BusinessRuleViolation('period_not_editable', 'Hanya periode draft yang dapat diubah. Deadline periode yang sudah dibuka diubah lewat penjadwalan ulang beralasan.');
            }

            $before = self::auditValues([
                'code' => $period->code,
                'name' => $period->name,
                'period_type' => $period->period_type,
                'starts_on' => $period->starts_on,
                'ends_on' => $period->ends_on,
                'organization_unit_id' => $period->organization_unit_id,
                'assignment_reference_date' => $period->assignment_reference_date,
                'notes' => $period->notes,
                'deadlines' => $period->deadlineMap(),
            ]);
            $after = self::auditValues($valid);

            if ($before === $after) {
                throw new BusinessRuleViolation('no_change', 'Tidak ada perubahan pada periode.');
            }

            $this->assertScopeUnit($valid['organization_unit_id'], $valid['assignment_reference_date']);
            $this->assertNoOverlap($valid, $period->id, false);

            if (AssessmentPeriod::query()->where('code', $valid['code'])->whereKeyNot($period->id)->exists()) {
                throw new BusinessRuleViolation('period_code_taken', 'Kode periode sudah digunakan.');
            }

            try {
                $period = $this->lock->update($period, $expectedVersion, self::attributes($valid));
            } catch (UniqueConstraintViolationException) {
                throw new BusinessRuleViolation('period_duplicate', 'Periode dengan tipe, scope, dan rentang tanggal yang sama sudah ada.');
            }

            AssessmentPeriodDeadline::query()->where('assessment_period_id', $period->id)->delete();
            $this->storeDeadlines($period, $valid['deadlines']);
            $this->record($actor, $period, 'assessment_period.draft_updated', $before, $after, null);

            return $period->load('deadlines');
        });
    }

    /**
     * DRAFT → OPEN. Stores who is assessed by whom; the snapshot never
     * changes afterwards.
     */
    public function open(Actor $actor, int $periodId, int $expectedVersion): AssessmentPeriod
    {
        return $this->transactions->run(function () use ($actor, $periodId, $expectedVersion): AssessmentPeriod {
            $type = AssessmentPeriod::query()->findOrFail($periodId)->period_type;

            // Serializes opening among the drafts of one type, so two
            // overlapping drafts created at the same moment cannot both be
            // opened. Opened periods are not locked: target entry holds
            // shared locks on them.
            $period = AssessmentPeriod::query()
                ->where('period_type', $type->value)
                ->where('status', PeriodStatus::Draft->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->firstWhere('id', $periodId)
                ?? AssessmentPeriod::query()->lockForUpdate()->findOrFail($periodId);

            $this->transitions->assertAllowed($period->status->value, PeriodStatus::Open->value, PeriodStatus::transitions());
            $this->assertScopeUnit($period->organization_unit_id, $period->assignment_reference_date);
            $this->assertNoOverlap([
                'period_type' => $period->period_type,
                'starts_on' => $period->starts_on,
                'ends_on' => $period->ends_on,
                'organization_unit_id' => $period->organization_unit_id,
                'assignment_reference_date' => $period->assignment_reference_date,
            ], $period->id, true);

            $now = CarbonImmutable::now();
            $plan = $this->snapshots->plan($period, $now);
            $covered = count(array_filter($plan, static fn (PlannedAssignment $planned): bool => $planned->coverage === Coverage::Covered));

            if ($covered === 0) {
                throw new BusinessRuleViolation('no_covered_assignment', 'Periode belum dapat dibuka: tidak ada pegawai atau unit dalam scope yang memiliki template published dan berlaku pada tanggal acuan.');
            }

            $period = $this->lock->update($period, $expectedVersion, [
                'status' => PeriodStatus::Open->value,
                'opened_by_user_id' => $actor->userId,
                'opened_at' => $now,
            ]);

            $this->storeSnapshot($period, $plan, $now);

            foreach ($this->participants as $participant) {
                $participant->periodOpened($period, $actor);
            }

            $summary = self::planSummary($plan);
            $this->record($actor, $period, 'assessment_period.opened', ['status' => PeriodStatus::Draft->value], [
                'status' => PeriodStatus::Open->value,
                'assignment_reference_date' => $period->assignment_reference_date,
                ...$summary,
            ], null);

            $this->outbox->publish(new OutboxEvent(
                eventType: 'PeriodOpened',
                eventVersion: 1,
                aggregateType: 'assessment_period',
                aggregateId: $period->public_id,
                payload: [
                    'code' => $period->code,
                    'period_type' => $period->period_type->value,
                    'starts_on' => $period->starts_on,
                    'ends_on' => $period->ends_on,
                    'timezone' => $period->timezone,
                    ...$summary,
                ],
                deduplicationKey: "period-opened:{$period->public_id}",
            ));

            return $period->load('deadlines');
        });
    }

    /**
     * OPEN → IN_PROGRESS, once the modules that depend on the period (target
     * setting) report no blocker.
     */
    public function startProgress(Actor $actor, int $periodId, int $expectedVersion): AssessmentPeriod
    {
        return $this->transactions->run(function () use ($actor, $periodId, $expectedVersion): AssessmentPeriod {
            $period = AssessmentPeriod::query()->with('deadlines')->lockForUpdate()->findOrFail($periodId);
            $this->transitions->assertAllowed($period->status->value, PeriodStatus::InProgress->value, PeriodStatus::transitions());

            $blockers = $this->blockersFor($period, PeriodStatus::InProgress);

            if ($blockers !== []) {
                throw new BusinessRuleViolation('period_not_ready', 'Periode belum dapat dilanjutkan: '.implode(' ', $blockers));
            }

            $period = $this->lock->update($period, $expectedVersion, [
                'status' => PeriodStatus::InProgress->value,
                'progress_started_by_user_id' => $actor->userId,
                'progress_started_at' => CarbonImmutable::now(),
            ]);

            $this->record($actor, $period, 'assessment_period.progress_started', ['status' => PeriodStatus::Open->value], ['status' => PeriodStatus::InProgress->value], null);

            return $period->load('deadlines');
        });
    }

    /**
     * IN_PROGRESS → REVIEW → FINALIZED → LOCKED. Each step is allowed only
     * when no dependent module reports a blocker (actuals verified, scores
     * complete, approvals done, corrections settled). Nothing moves backwards;
     * a locked period changes only through approved corrections.
     */
    public function advance(Actor $actor, int $periodId, int $expectedVersion, PeriodStatus $to): AssessmentPeriod
    {
        if (! in_array($to, [PeriodStatus::Review, PeriodStatus::Finalized, PeriodStatus::Locked], true)) {
            throw new BusinessRuleViolation('transition_not_available', 'Transisi periode ini tidak tersedia.');
        }

        return $this->transactions->run(function () use ($actor, $periodId, $expectedVersion, $to): AssessmentPeriod {
            $period = AssessmentPeriod::query()->with('deadlines')->lockForUpdate()->findOrFail($periodId);
            $from = $period->status;
            $this->transitions->assertAllowed($from->value, $to->value, PeriodStatus::transitions());

            $blockers = $this->blockersFor($period, $to);

            if ($blockers !== []) {
                throw new BusinessRuleViolation('period_not_ready', 'Periode belum dapat dilanjutkan: '.implode(' ', $blockers));
            }

            $period = $this->lock->update($period, $expectedVersion, ['status' => $to->value]);
            $this->record($actor, $period, 'assessment_period.status_changed', ['status' => $from->value], ['status' => $to->value], null);

            foreach ($this->participants as $participant) {
                $participant->periodAdvanced($period, $from, $actor);
            }

            if ($to === PeriodStatus::Locked) {
                $this->outbox->publish(new OutboxEvent(
                    eventType: 'PeriodLocked',
                    eventVersion: 1,
                    aggregateType: 'assessment_period',
                    aggregateId: $period->public_id,
                    payload: ['code' => $period->code, 'period_type' => $period->period_type->value, 'starts_on' => $period->starts_on, 'ends_on' => $period->ends_on],
                    deduplicationKey: "period-locked:{$period->public_id}",
                ));
            }

            return $period->load('deadlines');
        });
    }

    /**
     * Moves or removes one stage deadline of an opened period. This is the
     * only way a cut-off changes: there is no automatic grace period, and
     * every change carries a reason in the audit trail.
     */
    public function rescheduleDeadline(Actor $actor, int $periodId, int $expectedVersion, DeadlineStage $stage, ?string $dueOn, string $reason): AssessmentPeriod
    {
        $reason = self::requireText($reason, 'reason', 'Alasan perubahan deadline wajib diisi.');
        $dueOn = self::optionalText($dueOn, 10);

        if ($dueOn !== null) {
            self::requirePeriod($dueOn);
        }

        return $this->transactions->run(function () use ($actor, $periodId, $expectedVersion, $stage, $dueOn, $reason): AssessmentPeriod {
            $period = AssessmentPeriod::query()->with('deadlines')->lockForUpdate()->findOrFail($periodId);

            if (! in_array($period->status, [PeriodStatus::Open, PeriodStatus::InProgress, PeriodStatus::Review], true)) {
                throw new BusinessRuleViolation('deadline_not_reschedulable', 'Deadline hanya dapat dijadwalkan ulang pada periode Open, In progress, atau Review. Periode draft diubah lewat form periode.');
            }

            $deadlines = $period->deadlineMap();
            $before = $deadlines[$stage->value] ?? null;

            if ($before === $dueOn) {
                throw new BusinessRuleViolation('no_change', 'Deadline tidak berubah.');
            }

            if ($dueOn === null) {
                unset($deadlines[$stage->value]);
            } else {
                $deadlines[$stage->value] = $dueOn;
            }

            $problems = DeadlineStage::orderProblems($deadlines);

            if ($problems !== []) {
                throw new BusinessRuleViolation('invalid_deadlines', implode(' ', $problems));
            }

            $period = $this->lock->update($period, $expectedVersion, []);
            AssessmentPeriodDeadline::query()->where('assessment_period_id', $period->id)->where('stage', $stage->value)->delete();

            if ($dueOn !== null) {
                AssessmentPeriodDeadline::query()->create(['assessment_period_id' => $period->id, 'stage' => $stage, 'due_on' => $dueOn]);
            }

            $this->record($actor, $period, 'assessment_period.deadline_rescheduled', ['stage' => $stage->value, 'due_on' => $before], ['stage' => $stage->value, 'due_on' => $dueOn], $reason);

            return $period->load('deadlines');
        });
    }

    /**
     * @return list<string>
     */
    public function blockersFor(AssessmentPeriod $period, PeriodStatus $next): array
    {
        $blockers = [];

        foreach ($this->participants as $participant) {
            array_push($blockers, ...$participant->blockersFor($period, $next));
        }

        return $blockers;
    }

    /**
     * @return array{code: string, name: string, period_type: PeriodType, starts_on: string, ends_on: string, organization_unit_id: int, assignment_reference_date: string, notes: string|null, deadlines: array<string, string>}
     */
    private function validated(PeriodSetup $setup): array
    {
        $code = strtoupper(trim($setup->code));

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{1,59}$/', $code) !== 1) {
            throw new BusinessRuleViolation('invalid_code', 'Kode periode hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung (2–60 karakter).');
        }

        $name = self::requireText($setup->name, 'name', 'Nama periode wajib diisi.', 160);
        $type = PeriodType::tryFrom($setup->type) ?? throw new BusinessRuleViolation('invalid_period_type', 'Tipe periode tidak dikenal.');

        self::requirePeriod($setup->startsOn);
        self::requirePeriod($setup->endsOn);

        if ($setup->endsOn < $setup->startsOn) {
            throw new BusinessRuleViolation('invalid_period', 'Tanggal akhir periode tidak boleh sebelum tanggal mulai.');
        }

        $reference = self::optionalText($setup->referenceDate, 10) ?? $setup->startsOn;
        self::requirePeriod($reference);

        if ($reference < $setup->startsOn || $reference > $setup->endsOn) {
            throw new BusinessRuleViolation('invalid_reference_date', 'Tanggal acuan penugasan harus berada di dalam rentang periode.');
        }

        $deadlines = [];

        foreach ($setup->deadlines as $stageKey => $dueOn) {
            $stage = DeadlineStage::tryFrom($stageKey) ?? throw new BusinessRuleViolation('invalid_deadline_stage', 'Tahap deadline tidak dikenal.');
            $dueOn = self::optionalText($dueOn, 10);

            if ($dueOn !== null) {
                self::requirePeriod($dueOn);
                $deadlines[$stage->value] = $dueOn;
            }
        }

        $problems = DeadlineStage::orderProblems($deadlines);

        if ($problems !== []) {
            throw new BusinessRuleViolation('invalid_deadlines', implode(' ', $problems));
        }

        return [
            'code' => $code,
            'name' => $name,
            'period_type' => $type,
            'starts_on' => $setup->startsOn,
            'ends_on' => $setup->endsOn,
            'organization_unit_id' => $setup->unitId,
            'assignment_reference_date' => $reference,
            'notes' => self::optionalText($setup->notes),
            'deadlines' => $deadlines,
        ];
    }

    private function assertScopeUnit(int $unitId, string $referenceDate): void
    {
        if ($this->directory->snapshot($referenceDate)->unit($unitId) === null) {
            throw new BusinessRuleViolation('scope_unit_inactive', 'Unit scope periode tidak aktif pada tanggal acuan penugasan.');
        }
    }

    /**
     * Two periods of the same type may not cover the same subjects on the
     * same dates: their date ranges may only overlap when their scopes are
     * unrelated branches of the organization.
     *
     * @param  array{period_type: PeriodType, starts_on: string, ends_on: string, organization_unit_id: int, assignment_reference_date: string, ...}  $candidate
     */
    private function assertNoOverlap(array $candidate, ?int $ignoreId, bool $openedOnly): void
    {
        $query = AssessmentPeriod::query()
            ->where('period_type', $candidate['period_type']->value)
            ->where('starts_on', '<=', $candidate['ends_on'])
            ->where('ends_on', '>=', $candidate['starts_on']);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($openedOnly) {
            $query->where('status', '<>', PeriodStatus::Draft->value);
        }

        $snapshot = $this->directory->snapshot($candidate['assignment_reference_date']);
        $unitId = $candidate['organization_unit_id'];

        foreach ($query->orderBy('id')->get() as $other) {
            $related = $other->organization_unit_id === $unitId
                || $snapshot->contains($other->organization_unit_id, $unitId)
                || $snapshot->contains($unitId, $other->organization_unit_id);

            if (! $related) {
                continue;
            }

            if ($other->organization_unit_id === $unitId && $other->starts_on === $candidate['starts_on'] && $other->ends_on === $candidate['ends_on']) {
                throw new BusinessRuleViolation('period_duplicate', "Periode dengan tipe, scope, dan rentang tanggal yang sama sudah ada ({$other->code}).");
            }

            throw new BusinessRuleViolation('period_overlap', "Rentang tanggal bertumpang tindih dengan periode {$other->code} untuk scope organisasi yang sama.");
        }
    }

    /**
     * @param  array<string, string>  $deadlines
     */
    private function storeDeadlines(AssessmentPeriod $period, array $deadlines): void
    {
        foreach ($deadlines as $stage => $dueOn) {
            AssessmentPeriodDeadline::query()->create([
                'assessment_period_id' => $period->id,
                'stage' => $stage,
                'due_on' => $dueOn,
            ]);
        }
    }

    /**
     * @param  list<PlannedAssignment>  $plan
     */
    private function storeSnapshot(AssessmentPeriod $period, array $plan, CarbonImmutable $now): void
    {
        $approverRows = [];

        foreach ($plan as $planned) {
            $assignment = PeriodAssignment::query()->create([
                'assessment_period_id' => $period->id,
                'subject_type' => $planned->subjectType,
                'subject_key' => $planned->subjectKey(),
                'employee_id' => $planned->employeeId,
                'organization_unit_id' => $planned->unitId,
                'source_assignment_id' => $planned->sourceAssignmentId,
                'subject_code' => $planned->subjectCode,
                'subject_name' => $planned->subjectName,
                'position_title' => $planned->positionTitle,
                'unit_code' => $planned->unitCode,
                'unit_name' => $planned->unitName,
                'unit_type' => $planned->unitType,
                'unit_path' => $planned->unitPath,
                'kpi_template_version_id' => $planned->templateVersionId,
                'template_code' => $planned->templateCode,
                'template_version_number' => $planned->templateVersionNumber,
                'coverage' => $planned->coverage,
                'approver_gaps' => $planned->approverGaps === [] ? null : $planned->approverGaps,
            ]);

            foreach ($planned->approvers as $approver) {
                $approverRows[] = [
                    'period_assignment_id' => $assignment->id,
                    'stage' => $approver->stage->value,
                    'role' => $approver->role,
                    'user_id' => $approver->userId,
                    'user_name' => $approver->userName,
                    'scope_unit_id' => $approver->scopeUnitId,
                    'scope_unit_code' => $approver->scopeUnitCode,
                    'role_assignment_public_id' => $approver->roleAssignmentPublicId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($approverRows, self::APPROVER_INSERT_CHUNK) as $chunk) {
            PeriodAssignmentApprover::query()->insert($chunk);
        }
    }

    /**
     * @param  list<PlannedAssignment>  $plan
     * @return array{employees: int, units: int, covered: int, not_covered: int, approver_gaps: int}
     */
    public static function planSummary(array $plan): array
    {
        $summary = ['employees' => 0, 'units' => 0, 'covered' => 0, 'not_covered' => 0, 'approver_gaps' => 0];

        foreach ($plan as $planned) {
            $summary[$planned->subjectType === SubjectType::Employee ? 'employees' : 'units']++;
            $summary[$planned->coverage === Coverage::Covered ? 'covered' : 'not_covered']++;

            if ($planned->coverage === Coverage::Covered && $planned->approverGaps !== []) {
                $summary['approver_gaps']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array{code: string, name: string, period_type: PeriodType, starts_on: string, ends_on: string, organization_unit_id: int, assignment_reference_date: string, notes: string|null, ...}  $valid
     * @return array<string, mixed>
     */
    private static function attributes(array $valid): array
    {
        return [
            'code' => $valid['code'],
            'name' => $valid['name'],
            'period_type' => $valid['period_type']->value,
            'starts_on' => $valid['starts_on'],
            'ends_on' => $valid['ends_on'],
            'organization_unit_id' => $valid['organization_unit_id'],
            'assignment_reference_date' => $valid['assignment_reference_date'],
            'notes' => $valid['notes'],
        ];
    }

    /**
     * @param  array{code: string, name: string, period_type: PeriodType, starts_on: string, ends_on: string, organization_unit_id: int, assignment_reference_date: string, notes: string|null, deadlines: array<string, string>}  $valid
     * @return array<string, mixed>
     */
    private static function auditValues(array $valid): array
    {
        $deadlines = $valid['deadlines'];
        ksort($deadlines);

        return [...self::attributes($valid), 'deadlines' => $deadlines];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Actor $actor, AssessmentPeriod $period, string $action, ?array $before, ?array $after, ?string $reason): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'assessment_period',
            entityId: $period->public_id,
            before: $before,
            after: $after,
            reason: $reason,
            context: $actor->audit,
        ));
    }
}
