<?php

declare(strict_types=1);

namespace App\Modules\Target\Application;

use App\Modules\IdentityAccess\Application\UnitScope;
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
use App\Modules\Target\Domain\Exceptions\TargetEntriesRejected;
use App\Modules\Target\Domain\TargetStatus;
use App\Modules\Target\Domain\TargetValueRules;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTargetVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Target use cases: DRAFT → SUBMITTED → APPROVED per target version.
 *
 * - A batch of entries is saved completely or not at all.
 * - A final (approved) target is never edited; a change is a new version
 *   with a reason that is approved again.
 * - After the target cut-off (deadline passed, or the period is in
 *   progress) every new draft needs a reason and is flagged.
 * - The approver is never an account that wrote or submitted the version,
 *   nor the employee the target belongs to (PRD §4.1).
 *
 * Bulk operations take the actor's unit scope and touch only targets inside
 * it. Who approves targets per team is not decided (DEC-002); the capability
 * is held by HR Performance only until then.
 */
final readonly class TargetService
{
    use GuardsInput;

    private const MAX_ENTRIES = 5000;

    private const STALE_MESSAGE = 'Target ini baru saja diubah pengguna lain. Muat ulang halaman lalu periksa nilainya.';

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OutboxPublisher $outbox,
        private OptimisticLock $lock,
        private StateTransitionGuard $transitions,
        private PeriodDeadlines $deadlines,
    ) {}

    /**
     * @param  list<TargetEntry>  $entries
     * @return array{created: int, updated: int, unchanged: int}
     *
     * @throws TargetEntriesRejected when any entry is invalid; nothing is saved
     */
    public function saveDrafts(
        Actor $actor,
        int $periodId,
        UnitScope $scope,
        array $entries,
        ?string $reason = null,
        bool $allowRevision = false,
        string $source = PeriodTargetVersion::SOURCE_MANUAL,
        ?int $importBatchId = null,
    ): array {
        $reason = self::optionalText($reason);

        if ($entries === []) {
            throw new BusinessRuleViolation('no_entries', 'Tidak ada nilai target yang diisi.');
        }

        if (count($entries) > self::MAX_ENTRIES) {
            throw new BusinessRuleViolation('too_many_entries', 'Satu kali simpan maksimal '.self::MAX_ENTRIES.' target.');
        }

        return $this->transactions->run(function () use ($actor, $periodId, $scope, $entries, $reason, $allowRevision, $source, $importBatchId): array {
            $period = $this->periodAcceptingTargets($periodId);
            $afterCutoff = $this->isAfterCutoff($period);

            if ($afterCutoff && $reason === null) {
                throw new BusinessRuleViolation('cutoff_reason_required', 'Batas waktu penetapan target sudah lewat. Perubahan hanya dapat disimpan dengan alasan.');
            }

            $targetIds = array_values(array_unique(array_map(static fn (TargetEntry $entry): int => $entry->targetId, $entries)));
            $targets = PeriodTarget::query()
                ->where('assessment_period_id', $period->id)
                ->whereIn('id', $targetIds)
                ->with('definitionVersion')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $latest = $this->latestVersions($targetIds);
            $errors = [];
            $plans = [];
            $seen = [];

            foreach ($entries as $entry) {
                $target = $targets->get($entry->targetId);

                if ($target === null || ! $scope->coversUnit($target->organization_unit_id)) {
                    $errors[$entry->reference][] = 'Target tidak ditemukan pada periode ini dalam scope Anda.';

                    continue;
                }

                if (isset($seen[$target->id])) {
                    $errors[$entry->reference][] = 'Target yang sama diisi lebih dari satu kali.';

                    continue;
                }

                $seen[$target->id] = true;

                try {
                    $value = $target->valueRules()->parse($entry->value, $entry->unit);
                } catch (InvalidArgumentException $exception) {
                    $errors[$entry->reference][] = $exception->getMessage();

                    continue;
                }

                $problem = $this->plan($plans, $entry, $target, $latest[$target->id] ?? null, $value, $reason, $allowRevision);

                if ($problem !== null) {
                    $errors[$entry->reference][] = $problem;
                }
            }

            if ($errors !== []) {
                throw new TargetEntriesRejected($errors);
            }

            $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

            foreach ($plans as $plan) {
                $result[$plan['action']]++;

                if ($plan['action'] !== 'unchanged') {
                    $this->apply($actor, $plan, $reason, $afterCutoff, $source, $importBatchId);
                }
            }

            return $result;
        });
    }

    /**
     * Submits the draft targets inside the scope for approval.
     *
     * @param  list<int>|null  $targetIds  limit to these targets; null = every draft in scope
     */
    public function submit(Actor $actor, int $periodId, UnitScope $scope, ?array $targetIds = null): int
    {
        return $this->transactions->run(function () use ($actor, $periodId, $scope, $targetIds): int {
            $this->periodAcceptingTargets($periodId);
            $versions = $this->versionsIn($periodId, $scope, $targetIds, TargetStatus::Draft);

            if ($versions->isEmpty()) {
                throw new BusinessRuleViolation('nothing_to_submit', 'Tidak ada target draft untuk diajukan.');
            }

            $now = CarbonImmutable::now();

            foreach ($versions as $version) {
                $this->transitions->assertAllowed($version->status->value, TargetStatus::Submitted->value, TargetStatus::transitions());
                $this->lock->update($version, $version->lock_version, [
                    'status' => TargetStatus::Submitted->value,
                    'submitted_by_user_id' => $actor->userId,
                    'submitted_at' => $now,
                ]);

                $this->record($actor, $version->target, 'target.submitted', ['status' => TargetStatus::Draft->value], [
                    'status' => TargetStatus::Submitted->value,
                    'version' => $version->version_number,
                    'target_value' => TargetValueRules::display($version->target_value),
                ], null);
            }

            return $versions->count();
        });
    }

    /**
     * Approves the submitted targets inside the scope. Versions the actor
     * created, last edited, or submitted, and targets of the actor's own
     * employee record, are left for another approver.
     *
     * @param  list<int>|null  $targetIds
     * @return array{approved: int, skipped: int}
     */
    public function approve(Actor $actor, int $periodId, UnitScope $scope, ?array $targetIds = null): array
    {
        return $this->transactions->run(function () use ($actor, $periodId, $scope, $targetIds): array {
            $period = $this->periodAcceptingTargets($periodId);
            $versions = $this->versionsIn($periodId, $scope, $targetIds, TargetStatus::Submitted);

            if ($versions->isEmpty()) {
                throw new BusinessRuleViolation('nothing_to_approve', 'Tidak ada target yang menunggu persetujuan.');
            }

            $now = CarbonImmutable::now();
            $result = ['approved' => 0, 'skipped' => 0];

            foreach ($versions as $version) {
                $target = $version->target;

                if ($actor->userId !== null && in_array($actor->userId, [
                    $version->created_by_user_id,
                    $version->updated_by_user_id,
                    $version->submitted_by_user_id,
                    $target->employee?->user_id,
                ], true)) {
                    $result['skipped']++;

                    continue;
                }

                $this->transitions->assertAllowed($version->status->value, TargetStatus::Approved->value, TargetStatus::transitions());
                $this->lock->update($version, $version->lock_version, [
                    'status' => TargetStatus::Approved->value,
                    'approved_by_user_id' => $actor->userId,
                    'approved_at' => $now,
                ]);

                $this->record($actor, $target, 'target.approved', ['status' => TargetStatus::Submitted->value], [
                    'status' => TargetStatus::Approved->value,
                    'version' => $version->version_number,
                    'target_value' => TargetValueRules::display($version->target_value),
                    'unit' => $version->unit,
                ], $version->change_reason);

                $this->outbox->publish(new OutboxEvent(
                    eventType: 'TargetApproved',
                    eventVersion: 1,
                    aggregateType: 'period_target',
                    aggregateId: $target->public_id,
                    payload: [
                        'period_id' => $period->public_id,
                        'kpi_code' => $target->kpi_code,
                        'subject_type' => $target->subject_type->value,
                        'version' => $version->version_number,
                        'is_revision' => $version->previous_version_id !== null,
                    ],
                    deduplicationKey: "target-approved:{$version->public_id}",
                ));

                $result['approved']++;
            }

            if ($result['approved'] === 0) {
                throw new BusinessRuleViolation('self_review', 'Target yang Anda isi atau ajukan, atau target milik Anda sendiri, harus disetujui oleh orang lain.');
            }

            return $result;
        });
    }

    public function returnToDraft(Actor $actor, int $versionId, int $expectedVersion, string $reason): PeriodTargetVersion
    {
        $reason = self::requireText($reason, 'reason', 'Alasan pengembalian wajib diisi.');

        return $this->transactions->run(function () use ($actor, $versionId, $expectedVersion, $reason): PeriodTargetVersion {
            $version = PeriodTargetVersion::query()->with('target')->lockForUpdate()->findOrFail($versionId);
            $this->periodAcceptingTargets($version->target->assessment_period_id);
            $this->transitions->assertAllowed($version->status->value, TargetStatus::Draft->value, TargetStatus::transitions());

            $version = $this->lock->update($version, $expectedVersion, [
                'status' => TargetStatus::Draft->value,
                'returned_by_user_id' => $actor->userId,
                'returned_at' => CarbonImmutable::now(),
                'return_reason' => $reason,
            ]);

            $this->record($actor, $version->target, 'target.returned', ['status' => TargetStatus::Submitted->value], [
                'status' => TargetStatus::Draft->value,
                'version' => $version->version_number,
            ], $reason);

            return $version;
        });
    }

    public function isAfterCutoff(AssessmentPeriod $period): bool
    {
        return $period->status === PeriodStatus::InProgress
            || $this->deadlines->hasPassed($period, DeadlineStage::TargetSetting, CarbonImmutable::now());
    }

    /**
     * Shared lock: target changes may run side by side, but not while the
     * period itself is changing status.
     */
    private function periodAcceptingTargets(int $periodId): AssessmentPeriod
    {
        $period = AssessmentPeriod::query()->with('deadlines')->sharedLock()->findOrFail($periodId);

        if (! $period->status->acceptsTargetChanges()) {
            throw new BusinessRuleViolation('period_not_accepting_targets', 'Target hanya dapat diubah pada periode Open atau In progress. Perubahan setelah itu memerlukan proses koreksi.');
        }

        return $period;
    }

    /**
     * Decides what an entry does to its target. Returns a safe message when
     * the entry cannot be applied.
     *
     * @param  list<array{action: 'created'|'updated'|'unchanged', target: PeriodTarget, latest: PeriodTargetVersion|null, value: string, note: string|null}>  $plans
     */
    private function plan(array &$plans, TargetEntry $entry, PeriodTarget $target, ?PeriodTargetVersion $latest, string $value, ?string $reason, bool $allowRevision): ?string
    {
        $note = self::optionalText($entry->sourceNote, 500);
        $plan = ['target' => $target, 'latest' => $latest, 'value' => $value, 'note' => $note];

        if ($latest === null) {
            if ($entry->expectedLockVersion !== null && $entry->expectedLockVersion > 0) {
                return self::STALE_MESSAGE;
            }

            $plans[] = ['action' => 'created', ...$plan];

            return null;
        }

        if ($latest->status === TargetStatus::Submitted) {
            return 'Target sedang menunggu persetujuan. Kembalikan ke draft sebelum mengubahnya.';
        }

        if ($latest->status === TargetStatus::Approved) {
            if (! $allowRevision) {
                return 'Target sudah final dan tidak dapat ditimpa. Gunakan revisi dengan alasan.';
            }

            if ($reason === null) {
                return 'Revisi target final wajib disertai alasan.';
            }

            if ($latest->target_value === $value) {
                return 'Nilai revisi sama dengan target final yang berlaku.';
            }

            $plans[] = ['action' => 'created', ...$plan];

            return null;
        }

        // 0 means the caller saw no draft; any draft that exists now is news.
        if ($entry->expectedLockVersion !== null && $entry->expectedLockVersion !== $latest->lock_version) {
            return self::STALE_MESSAGE;
        }

        $plans[] = ['action' => $latest->target_value === $value && $latest->source_note === $note ? 'unchanged' : 'updated', ...$plan];

        return null;
    }

    /**
     * @param  array{action: 'created'|'updated'|'unchanged', target: PeriodTarget, latest: PeriodTargetVersion|null, value: string, note: string|null}  $plan
     */
    private function apply(Actor $actor, array $plan, ?string $reason, bool $afterCutoff, string $source, ?int $importBatchId): void
    {
        $target = $plan['target'];
        $latest = $plan['latest'];
        $content = [
            'target_value' => $plan['value'],
            'unit' => (string) $target->definitionVersion->unit,
            'source' => $source,
            'source_note' => $plan['note'],
            'target_import_batch_id' => $importBatchId,
            'after_cutoff' => $afterCutoff,
            'updated_by_user_id' => $actor->userId,
        ];

        if ($plan['action'] === 'updated' && $latest !== null) {
            // Once flagged, a draft stays flagged even if the deadline moves.
            $version = $this->lock->update($latest, $latest->lock_version, [
                ...$content,
                'after_cutoff' => $latest->after_cutoff || $afterCutoff,
                'change_reason' => $reason ?? $latest->change_reason,
            ]);

            $this->record($actor, $target, 'target.draft_updated', ['version' => $latest->version_number, 'target_value' => TargetValueRules::display($latest->target_value)], self::auditValues($version), $reason);

            return;
        }

        $version = PeriodTargetVersion::query()->create([
            ...$content,
            'period_target_id' => $target->id,
            'version_number' => ($latest->version_number ?? 0) + 1,
            'status' => TargetStatus::Draft,
            'change_reason' => $reason,
            'previous_version_id' => $latest?->id,
            'created_by_user_id' => $actor->userId,
        ]);

        $this->record(
            $actor,
            $target,
            $latest === null ? 'target.draft_created' : 'target.revision_drafted',
            $latest === null ? null : ['version' => $latest->version_number, 'target_value' => TargetValueRules::display($latest->target_value)],
            self::auditValues($version),
            $reason,
        );
    }

    /**
     * @param  list<int>  $targetIds
     * @return array<int, PeriodTargetVersion> target id => newest version
     */
    private function latestVersions(array $targetIds): array
    {
        $latest = [];

        foreach (array_chunk($targetIds, 1000) as $chunk) {
            foreach (PeriodTargetVersion::query()->whereIn('period_target_id', $chunk)->orderBy('version_number')->get() as $version) {
                $latest[$version->period_target_id] = $version;
            }
        }

        return $latest;
    }

    /**
     * Versions in the given status for targets inside the scope, locked for
     * the rest of the transaction.
     *
     * @param  list<int>|null  $targetIds
     * @return Collection<int, PeriodTargetVersion>
     */
    private function versionsIn(int $periodId, UnitScope $scope, ?array $targetIds, TargetStatus $status): Collection
    {
        return PeriodTargetVersion::query()
            ->where('status', $status->value)
            ->whereHas('target', function (Builder $targets) use ($periodId, $scope, $targetIds): void {
                $targets->where('assessment_period_id', $periodId);

                if (! $scope->all) {
                    $targets->whereIn('organization_unit_id', $scope->unitIds === [] ? [0] : $scope->unitIds);
                }

                if ($targetIds !== null) {
                    $targets->whereIn('id', $targetIds === [] ? [0] : $targetIds);
                }
            })
            ->with('target.employee')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private static function auditValues(PeriodTargetVersion $version): array
    {
        return [
            'version' => $version->version_number,
            'target_value' => TargetValueRules::display($version->target_value),
            'unit' => $version->unit,
            'source' => $version->source,
            'source_note' => $version->source_note,
            'after_cutoff' => $version->after_cutoff,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Actor $actor, PeriodTarget $target, string $action, ?array $before, ?array $after, ?string $reason): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'period_target',
            entityId: $target->public_id,
            before: $before,
            after: $after,
            reason: $reason,
            context: $actor->audit,
        ));
    }
}
