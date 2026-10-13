<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Application;

use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Domain\EntryKind;
use App\Modules\ActualEvidence\Domain\Exceptions\ActualEntriesRejected;
use App\Modules\ActualEvidence\Domain\ObservationWindow;
use App\Modules\ActualEvidence\Domain\ScanStatus;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\PeriodAssignment\Application\PeriodDeadlines;
use App\Modules\PeriodAssignment\Domain\DeadlineStage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
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
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Actual use cases: DRAFT → SUBMITTED → VERIFIED, or back through REJECTED.
 *
 * - Actuals are entered only while the period is in progress.
 * - A batch of entries is saved completely or not at all.
 * - Zero is a value; "not applicable" is an explicit entry with a reason;
 *   a target without any entry is missing. The three are never merged.
 * - After the actual-submission deadline an entry needs a late reason and
 *   stays flagged as late.
 * - The verifier is never an account that wrote or submitted the entry, nor
 *   the employee it belongs to (PRD §4.1).
 * - A verified actual is final; changing it is the correction workflow of
 *   the next step.
 */
final readonly class ActualService
{
    use GuardsInput;

    private const MAX_ENTRIES = 5000;

    private const STALE_MESSAGE = 'Aktual ini baru saja diubah pengguna lain. Muat ulang halaman lalu periksa nilainya.';

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OutboxPublisher $outbox,
        private OptimisticLock $lock,
        private StateTransitionGuard $transitions,
        private PeriodDeadlines $deadlines,
    ) {}

    /**
     * @param  list<ActualEntry>  $entries
     * @return array{created: int, updated: int, unchanged: int}
     *
     * @throws ActualEntriesRejected when any entry is invalid; nothing is saved
     */
    public function saveDrafts(
        Actor $actor,
        int $periodId,
        ActualAccess $access,
        array $entries,
        ?string $lateReason = null,
        string $source = KpiActualVersion::SOURCE_MANUAL,
        ?int $importBatchId = null,
    ): array {
        $lateReason = self::optionalText($lateReason);

        if ($entries === []) {
            throw new BusinessRuleViolation('no_entries', 'Tidak ada nilai aktual yang diisi.');
        }

        if (count($entries) > self::MAX_ENTRIES) {
            throw new BusinessRuleViolation('too_many_entries', 'Satu kali simpan maksimal '.self::MAX_ENTRIES.' aktual.');
        }

        return $this->transactions->run(function () use ($actor, $periodId, $access, $entries, $lateReason, $source, $importBatchId): array {
            $period = $this->periodAcceptingActuals($periodId);
            $isLate = $this->isLate($period);

            if ($isLate && $lateReason === null) {
                throw new BusinessRuleViolation('late_reason_required', 'Batas waktu pengisian aktual sudah lewat. Aktual hanya dapat disimpan dengan alasan keterlambatan.');
            }

            $targetIds = array_values(array_unique(array_map(static fn (ActualEntry $entry): int => $entry->targetId, $entries)));
            $targets = PeriodTarget::query()
                ->where('assessment_period_id', $period->id)
                ->whereIn('id', $targetIds)
                ->with('definitionVersion')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var array<int, list<KpiActual>> $existing */
            $existing = [];

            foreach (array_chunk($targetIds, 1000) as $chunk) {
                foreach (KpiActual::query()->whereIn('period_target_id', $chunk)->with('versions')->orderBy('id')->get() as $actual) {
                    $existing[$actual->period_target_id][] = $actual;
                }
            }

            $errors = [];
            $plans = [];
            /** @var array<int, list<ObservationWindow>> $claimed windows taken by earlier entries of this batch */
            $claimed = [];

            foreach ($entries as $entry) {
                $target = $targets->get($entry->targetId);

                if ($target === null || ! $access->covers($target)) {
                    $errors[$entry->reference][] = 'Target tidak ditemukan pada periode ini dalam scope Anda.';

                    continue;
                }

                $problem = $this->plan($plans, $claimed, $entry, $target, $existing[$target->id] ?? [], $period);

                if ($problem !== null) {
                    $errors[$entry->reference][] = $problem;
                }
            }

            if ($errors !== []) {
                throw new ActualEntriesRejected($errors);
            }

            $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

            foreach ($plans as $plan) {
                $result[$plan['action']]++;

                if ($plan['action'] !== 'unchanged') {
                    $this->apply($actor, $plan, $isLate, $lateReason, $source, $importBatchId);
                }
            }

            return $result;
        });
    }

    /**
     * Submits the draft actuals inside the access scope for verification.
     *
     * @param  list<int>|null  $actualIds  limit to these actuals; null = every draft in scope
     * @param  bool  $ownDraftsOnly  only drafts the actor wrote last, so a bulk submit never locks someone else's unfinished entry
     */
    public function submit(Actor $actor, int $periodId, ActualAccess $access, ?array $actualIds = null, ?string $lateReason = null, bool $ownDraftsOnly = false): int
    {
        $lateReason = self::optionalText($lateReason);

        return $this->transactions->run(function () use ($actor, $periodId, $access, $actualIds, $lateReason, $ownDraftsOnly): int {
            $period = $this->periodAcceptingActuals($periodId);
            $versions = $this->versionsIn($periodId, $access, $actualIds, ActualStatus::Draft);

            if ($ownDraftsOnly) {
                $versions = $versions->filter(fn (KpiActualVersion $version): bool => $version->updated_by_user_id === $actor->userId)->values();
            }

            if ($versions->isEmpty()) {
                throw new BusinessRuleViolation('nothing_to_submit', 'Tidak ada aktual draft untuk diajukan.');
            }

            $isLate = $this->isLate($period);

            if ($isLate && $lateReason === null && $versions->contains(fn (KpiActualVersion $version): bool => ! $version->is_late)) {
                throw new BusinessRuleViolation('late_reason_required', 'Batas waktu pengisian aktual sudah lewat. Pengajuan memerlukan alasan keterlambatan.');
            }

            $withoutEvidence = $versions
                ->filter(fn (KpiActualVersion $version): bool => $this->evidenceProblem($version, false) !== null)
                ->map(fn (KpiActualVersion $version): string => "{$version->actual->target->kpi_code}/{$version->actual->target->subject_code}")
                ->values();

            if ($withoutEvidence->isNotEmpty()) {
                throw new BusinessRuleViolation('evidence_required', "{$withoutEvidence->count()} aktual belum memiliki bukti wajib yang dapat dipakai: ".$withoutEvidence->take(5)->implode(', ').($withoutEvidence->count() > 5 ? ', …' : '').'.');
            }

            $now = CarbonImmutable::now();

            foreach ($versions as $version) {
                $this->transitions->assertAllowed($version->status->value, ActualStatus::Submitted->value, ActualStatus::transitions());
                $becomesLate = $isLate && ! $version->is_late;

                $this->lock->update($version, $version->lock_version, [
                    'status' => ActualStatus::Submitted->value,
                    'submitted_by_user_id' => $actor->userId,
                    'submitted_at' => $now,
                    ...($becomesLate ? ['is_late' => true, 'late_reason' => $lateReason] : []),
                ]);

                $this->record($actor, $version->actual, 'actual.submitted', ['status' => ActualStatus::Draft->value], [
                    'status' => ActualStatus::Submitted->value,
                    'version' => $version->version_number,
                    'value' => $version->displayValue(),
                    'is_late' => $version->is_late || $becomesLate,
                ], $becomesLate ? $lateReason : null);

                $this->publish('ActualSubmitted', $period, $version);
            }

            return $versions->count();
        });
    }

    /**
     * Verifies the submitted actuals inside the access scope.
     *
     * @param  list<int>|null  $actualIds
     * @return array{verified: int, skipped_self: int, blocked_evidence: int}
     */
    public function verify(Actor $actor, int $periodId, ActualAccess $access, ?array $actualIds = null): array
    {
        return $this->transactions->run(function () use ($actor, $periodId, $access, $actualIds): array {
            $period = $this->periodAcceptingActuals($periodId);
            $versions = $this->versionsIn($periodId, $access, $actualIds, ActualStatus::Submitted);

            if ($versions->isEmpty()) {
                throw new BusinessRuleViolation('nothing_to_verify', 'Tidak ada aktual yang menunggu verifikasi.');
            }

            $now = CarbonImmutable::now();
            $result = ['verified' => 0, 'skipped_self' => 0, 'blocked_evidence' => 0];

            foreach ($versions as $version) {
                $target = $version->actual->target;

                if ($actor->userId !== null && in_array($actor->userId, [
                    $version->created_by_user_id,
                    $version->updated_by_user_id,
                    $version->submitted_by_user_id,
                    $target->employee?->user_id,
                ], true)) {
                    $result['skipped_self']++;

                    continue;
                }

                // A verifier must be able to open the evidence they vouch for.
                if ($this->evidenceProblem($version, true) !== null) {
                    $result['blocked_evidence']++;

                    continue;
                }

                $this->transitions->assertAllowed($version->status->value, ActualStatus::Verified->value, ActualStatus::transitions());
                $this->lock->update($version, $version->lock_version, [
                    'status' => ActualStatus::Verified->value,
                    'verified_by_user_id' => $actor->userId,
                    'verified_at' => $now,
                ]);

                $this->record($actor, $version->actual, 'actual.verified', ['status' => ActualStatus::Submitted->value], [
                    'status' => ActualStatus::Verified->value,
                    'version' => $version->version_number,
                    'value' => $version->displayValue(),
                ], null);

                $this->publish('ActualVerified', $period, $version);
                $result['verified']++;
            }

            if ($result['verified'] === 0) {
                throw $result['blocked_evidence'] > 0
                    ? new BusinessRuleViolation('evidence_not_cleared', 'Aktual belum dapat diverifikasi: bukti wajibnya belum lolos pemindaian atau terinfeksi.')
                    : new BusinessRuleViolation('self_review', 'Aktual yang Anda isi atau ajukan, atau aktual milik Anda sendiri, harus diverifikasi oleh orang lain.');
            }

            return $result;
        });
    }

    public function reject(Actor $actor, int $versionId, int $expectedVersion, ActualAccess $access, string $reason): KpiActualVersion
    {
        $reason = self::requireText($reason, 'reason', 'Alasan penolakan wajib diisi.');

        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion, $access, $reason): KpiActualVersion {
            $version = KpiActualVersion::query()->with('actual.target.employee')->lockForUpdate()->findOrFail($versionId);
            $target = $version->actual->target;
            $this->periodAcceptingActuals($target->assessment_period_id);

            if (! $access->covers($target)) {
                throw new BusinessRuleViolation('actual_out_of_scope', 'Aktual tidak ditemukan dalam scope Anda.');
            }

            if ($actor->userId !== null && in_array($actor->userId, [$version->updated_by_user_id, $version->submitted_by_user_id, $target->employee?->user_id], true)) {
                throw new BusinessRuleViolation('self_review', 'Aktual yang Anda isi atau ajukan, atau aktual milik Anda sendiri, harus ditinjau oleh orang lain.');
            }
            $this->transitions->assertAllowed($version->status->value, ActualStatus::Rejected->value, ActualStatus::transitions());

            $version = $this->lock->update($version, $expectedVersion, [
                'status' => ActualStatus::Rejected->value,
                'rejected_by_user_id' => $actor->userId,
                'rejected_at' => CarbonImmutable::now(),
                'reject_reason' => $reason,
            ]);

            $this->record($actor, $version->actual, 'actual.rejected', ['status' => ActualStatus::Submitted->value], [
                'status' => ActualStatus::Rejected->value,
                'version' => $version->version_number,
            ], $reason);

            return $version;
        });
    }

    public function isLate(AssessmentPeriod $period): bool
    {
        return $this->deadlines->hasPassed($period, DeadlineStage::ActualSubmission, CarbonImmutable::now());
    }

    /**
     * Why the evidence of an entry does not allow the next step, or null.
     * Submitting needs a file that is not infected; verifying needs one that
     * can actually be opened.
     */
    public function evidenceProblem(KpiActualVersion $version, bool $mustBeServable): ?string
    {
        $evidence = $version->actual->evidence;

        if ($evidence->contains(fn ($file): bool => $file->scan_status === ScanStatus::Infected)) {
            return 'Terdapat file bukti yang terinfeksi.';
        }

        $required = $version->actual->target->definitionVersion->evidence_requirement === 'required'
            && $version->entry_kind === EntryKind::Value;

        if (! $required) {
            return null;
        }

        $usable = $mustBeServable
            ? $evidence->filter(fn ($file): bool => $file->scan_status->isServable())
            : $evidence;

        return $usable->isEmpty() ? 'Bukti wajib belum tersedia.' : null;
    }

    private function periodAcceptingActuals(int $periodId): AssessmentPeriod
    {
        $period = AssessmentPeriod::query()->with('deadlines')->sharedLock()->findOrFail($periodId);

        if ($period->status !== PeriodStatus::InProgress) {
            throw new BusinessRuleViolation('period_not_accepting_actuals', 'Aktual hanya dapat diisi dan diverifikasi pada periode In progress.');
        }

        return $period;
    }

    /**
     * @param  list<array{action: 'created'|'updated'|'unchanged', target: PeriodTarget, actual: KpiActual|null, latest: KpiActualVersion|null, window: ObservationWindow, kind: EntryKind, value: string|null, note: string|null, entry: ActualEntry}>  $plans
     * @param  array<int, list<ObservationWindow>>  $claimed
     * @param  list<KpiActual>  $existing
     */
    private function plan(array &$plans, array &$claimed, ActualEntry $entry, PeriodTarget $target, array $existing, AssessmentPeriod $period): ?string
    {
        $definition = $target->definitionVersion;

        try {
            $window = ObservationWindow::for($definition->frequency, $entry->windowStart, $entry->windowEnd, $period->starts_on, $period->ends_on);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        $note = self::optionalText($entry->note, 1000);
        $sourceSystem = self::optionalText($entry->sourceSystem, 64);
        $sourceReference = self::optionalText($entry->sourceReference, 191);

        if (($sourceSystem === null) !== ($sourceReference === null)) {
            return 'Sistem sumber dan referensi record sumber harus diisi bersamaan.';
        }

        if ($entry->value === null || trim($entry->value) === '') {
            if ($note === null) {
                return 'Aktual N/A wajib disertai catatan alasan.';
            }

            $kind = EntryKind::NotApplicable;
            $value = null;
        } else {
            try {
                $value = $target->valueRules()->named('aktual')->parse($entry->value, $entry->unit);
            } catch (InvalidArgumentException $exception) {
                return $exception->getMessage();
            }

            $kind = EntryKind::Value;
        }

        $actual = null;

        foreach ($existing as $candidate) {
            if ($candidate->window_start === $window->start && $candidate->window_end === $window->end) {
                $actual = $candidate;
            } elseif ($window->overlaps($candidate->window_start, $candidate->window_end)) {
                return "Rentang observasi bertumpang tindih dengan aktual lain ({$candidate->windowLabel()}).";
            }

            if ($sourceSystem !== null && $candidate->source_system === $sourceSystem && $candidate->source_reference === $sourceReference
                && ($candidate->window_start !== $window->start || $candidate->window_end !== $window->end)) {
                return 'Record sumber ini sudah dipakai oleh aktual lain untuk target yang sama.';
            }
        }

        foreach ($claimed[$target->id] ?? [] as $taken) {
            if ($taken->overlaps($window->start, $window->end)) {
                return 'Rentang observasi yang sama atau bertumpang tindih diisi lebih dari satu kali.';
            }
        }

        // The source record is part of the actual's identity; it is set when
        // the actual is created and never silently replaced or dropped.
        if ($actual !== null && $sourceSystem !== null && ($actual->source_system !== $sourceSystem || $actual->source_reference !== $sourceReference)) {
            return 'Aktual untuk rentang ini sudah ada dengan referensi sumber yang berbeda atau tanpa referensi sumber.';
        }

        $claimed[$target->id][] = $window;
        $latest = $actual?->versions->last();
        $plan = ['target' => $target, 'actual' => $actual, 'latest' => $latest, 'window' => $window, 'kind' => $kind, 'value' => $value, 'note' => $note, 'entry' => $entry];

        if ($latest === null) {
            if ($entry->expectedLockVersion !== null && $entry->expectedLockVersion > 0) {
                return self::STALE_MESSAGE;
            }

            $plans[] = ['action' => 'created', ...$plan];

            return null;
        }

        if ($latest->status === ActualStatus::Submitted) {
            return 'Aktual sedang menunggu verifikasi dan tidak dapat diubah.';
        }

        if ($latest->status === ActualStatus::Verified) {
            return 'Aktual sudah terverifikasi dan tidak dapat diubah. Perubahan memerlukan proses koreksi.';
        }

        if ($entry->expectedLockVersion !== null && $entry->expectedLockVersion !== $latest->lock_version) {
            return self::STALE_MESSAGE;
        }

        $same = $latest->status === ActualStatus::Draft
            && $latest->entry_kind === $kind
            && $latest->actual_value === $value
            && $latest->note === $note;

        $plans[] = ['action' => $same ? 'unchanged' : 'updated', ...$plan];

        return null;
    }

    /**
     * @param  array{action: 'created'|'updated'|'unchanged', target: PeriodTarget, actual: KpiActual|null, latest: KpiActualVersion|null, window: ObservationWindow, kind: EntryKind, value: string|null, note: string|null, entry: ActualEntry}  $plan
     */
    private function apply(Actor $actor, array $plan, bool $isLate, ?string $lateReason, string $source, ?int $importBatchId): void
    {
        $target = $plan['target'];
        $latest = $plan['latest'];
        $content = [
            'entry_kind' => $plan['kind']->value,
            'actual_value' => $plan['value'],
            'unit' => (string) $target->definitionVersion->unit,
            'source' => $source,
            'actual_import_batch_id' => $importBatchId,
            'note' => $plan['note'],
            'updated_by_user_id' => $actor->userId,
        ];

        $actual = $plan['actual'] ?? KpiActual::query()->create([
            'period_target_id' => $target->id,
            'window_start' => $plan['window']->start,
            'window_end' => $plan['window']->end,
            'source_system' => self::optionalText($plan['entry']->sourceSystem, 64),
            'source_reference' => self::optionalText($plan['entry']->sourceReference, 191),
        ]);

        if ($latest !== null) {
            $before = ['status' => $latest->status->value, 'value' => $latest->displayValue()];

            // A rejected entry returns to draft first; content is editable only as a draft.
            if ($latest->status === ActualStatus::Rejected) {
                $latest = $this->lock->update($latest, $latest->lock_version, ['status' => ActualStatus::Draft->value]);
            }

            $version = $this->lock->update($latest, $latest->lock_version, [
                ...$content,
                'is_late' => $latest->is_late || $isLate,
                'late_reason' => $latest->late_reason ?? ($isLate ? $lateReason : null),
            ]);

            $this->record($actor, $actual, 'actual.draft_updated', $before, self::auditValues($actual, $version), $isLate ? $lateReason : null);

            return;
        }

        $version = KpiActualVersion::query()->create([
            ...$content,
            'kpi_actual_id' => $actual->id,
            'version_number' => 1,
            'status' => ActualStatus::Draft,
            'is_late' => $isLate,
            'late_reason' => $isLate ? $lateReason : null,
            'created_by_user_id' => $actor->userId,
        ]);

        $this->record($actor, $actual, 'actual.draft_created', null, self::auditValues($actual, $version), $isLate ? $lateReason : null);
    }

    /**
     * Newest versions in the given status for actuals inside the access
     * scope, locked for the rest of the transaction.
     *
     * @param  list<int>|null  $actualIds
     * @return Collection<int, KpiActualVersion>
     */
    private function versionsIn(int $periodId, ActualAccess $access, ?array $actualIds, ActualStatus $status): Collection
    {
        return KpiActualVersion::query()
            ->where('status', $status->value)
            ->whereHas('actual', function (Builder $actuals) use ($periodId, $access, $actualIds): void {
                if ($actualIds !== null) {
                    $actuals->whereIn('id', $actualIds === [] ? [0] : $actualIds);
                }

                $actuals->whereHas('target', function (Builder $targets) use ($periodId, $access): void {
                    $targets->where('assessment_period_id', $periodId);
                    $access->apply($targets);
                });
            })
            ->with(['actual.target.definitionVersion', 'actual.target.employee'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->tap(function (Collection $versions): void {
                // Evidence changes lock the actual row first, so holding it
                // here means the evidence read below cannot go stale before
                // the status changes.
                KpiActual::query()->whereIn('id', $versions->pluck('kpi_actual_id')->all())->orderBy('id')->lockForUpdate()->get();
                $versions->load('actual.evidence');
            });
    }

    private function publish(string $eventType, AssessmentPeriod $period, KpiActualVersion $version): void
    {
        $actual = $version->actual;

        $this->outbox->publish(new OutboxEvent(
            eventType: $eventType,
            eventVersion: 1,
            aggregateType: 'kpi_actual',
            aggregateId: $actual->public_id,
            payload: [
                'period_id' => $period->public_id,
                'target_id' => $actual->target->public_id,
                'kpi_code' => $actual->target->kpi_code,
                'subject_type' => $actual->target->subject_type->value,
                'window_start' => $actual->window_start,
                'window_end' => $actual->window_end,
                'version' => $version->version_number,
            ],
            deduplicationKey: mb_strtolower($eventType).":{$version->public_id}:{$version->lock_version}",
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function auditValues(KpiActual $actual, KpiActualVersion $version): array
    {
        return [
            'version' => $version->version_number,
            'status' => $version->status->value,
            'entry_kind' => $version->entry_kind->value,
            'value' => $version->displayValue(),
            'unit' => $version->unit,
            'window_start' => $actual->window_start,
            'window_end' => $actual->window_end,
            'source' => $version->source,
            'source_system' => $actual->source_system,
            'source_reference' => $actual->source_reference,
            'is_late' => $version->is_late,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Actor $actor, KpiActual $actual, string $action, ?array $before, ?array $after, ?string $reason): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'kpi_actual',
            entityId: $actual->public_id,
            before: $before,
            after: $after,
            reason: $reason,
            context: $actor->audit,
        ));
    }
}
