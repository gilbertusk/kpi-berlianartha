<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Application;

use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Scoring\Domain\Exact;
use App\Modules\Scoring\Domain\KpiLineResult;
use App\Modules\Scoring\Domain\ScorecardCalculator;
use App\Modules\Scoring\Domain\ScorecardInput;
use App\Modules\Scoring\Domain\ScorecardPolicy;
use App\Modules\Scoring\Domain\ScorecardResult;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculationLine;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Outbox\OutboxEvent;
use App\Modules\Shared\Application\Outbox\OutboxPublisher;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Application\Validation\GuardsInput;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Scorecard calculation use case.
 *
 * - Runs only with an approved policy in force on the period's reference
 *   date. While DEC-004 is open there is none, and the run is refused.
 * - Runs only while the period is in progress or in review. A finalized or
 *   locked period changes only through the correction workflow (Step 10).
 * - Idempotent: a subject whose input and rules did not change keeps its
 *   latest calculation. A changed subject gets a new immutable calculation
 *   with the next sequence number and the reason; nothing is overwritten.
 * - A policy version that was used once must keep its content; if the
 *   configuration of that version changed, the run is refused.
 */
final readonly class ScoringService
{
    use GuardsInput;

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private OutboxPublisher $outbox,
        private ScorecardPolicyCatalog $policies,
        private ScorecardInputLoader $inputs,
        private ScorecardCalculator $calculator,
    ) {}

    /**
     * @param  list<int>|null  $assignmentIds  limit to these subjects; null = every covered subject in scope
     * @return array{created: int, unchanged: int, complete: int, incomplete: int}
     */
    public function calculate(Actor $actor, int $periodId, ScoreAccess $access, string $reason, ?array $assignmentIds = null): array
    {
        $reason = self::requireText($reason, 'reason', 'Alasan perhitungan wajib diisi.');

        try {
            return $this->run($actor, $periodId, $access, $reason, $assignmentIds);
        } catch (UniqueConstraintViolationException) {
            // Another run stored the same sequence number first.
            throw new BusinessRuleViolation('scorecard_concurrent_run', 'Perhitungan lain untuk scorecard yang sama sedang berjalan. Muat ulang halaman lalu coba lagi.');
        }
    }

    /**
     * Recalculates every scorecard of a period after an approved correction.
     * This is the only way scores change once a period is in review, final,
     * or locked; the caller is the correction workflow, which has recorded
     * the approval. Unchanged scorecards keep their calculation.
     *
     * @return array{created: int, unchanged: int, complete: int, incomplete: int}
     */
    public function recalculateForCorrection(Actor $actor, int $periodId, string $reason): array
    {
        return $this->run($actor, $periodId, ScoreAccess::everything(), self::requireText($reason, 'reason', 'Alasan perhitungan wajib diisi.'), null, true);
    }

    /**
     * @param  list<int>|null  $assignmentIds
     * @return array{created: int, unchanged: int, complete: int, incomplete: int}
     */
    private function run(Actor $actor, int $periodId, ScoreAccess $access, string $reason, ?array $assignmentIds, bool $correction = false): array
    {
        return $this->transactions->run(function () use ($actor, $periodId, $access, $reason, $assignmentIds, $correction): array {
            $period = AssessmentPeriod::query()->sharedLock()->findOrFail($periodId);
            self::assertScorable($period, $correction);
            $policy = $this->policyFor($period);
            $this->assertPolicyUnchanged($policy);

            $assignments = $this->assignments($period, $access, $assignmentIds);
            $latest = $this->latestCalculations($assignments);
            $inputs = $this->inputs->load($period, $assignments);
            $targetIds = PeriodTarget::query()->where('assessment_period_id', $period->id)->pluck('id', 'public_id')->all();
            $summary = ['created' => 0, 'unchanged' => 0, 'complete' => 0, 'incomplete' => 0];

            foreach ($assignments as $assignment) {
                $input = $inputs[$assignment->id];
                $fingerprint = ScorecardCalculator::fingerprint($input, $policy);
                $previous = $latest[$assignment->id] ?? null;

                if ($previous?->input_hash === $fingerprint) {
                    $summary['unchanged']++;
                    $summary[$previous->status->value]++;

                    continue;
                }

                $result = $this->calculator->calculate($input, $policy);
                $this->store($actor, $period, $assignment, $previous, $policy, $input, $fingerprint, $result, $reason, $targetIds);
                $summary['created']++;
                $summary[$result->status->value]++;
            }

            $this->audit->record(new AuditEntry(
                action: 'scorecard.calculation_run',
                entityType: 'assessment_period',
                entityId: $period->public_id,
                before: null,
                after: [...$summary, 'subjects' => $assignments->count(), 'policy' => $policy->reference(), 'engine_version' => ScorecardCalculator::ENGINE_VERSION],
                reason: $reason,
                context: $actor->audit,
            ));

            return $summary;
        });
    }

    /**
     * The approved policy for the period, or a refusal that says why.
     */
    public function policyFor(AssessmentPeriod $period): ScorecardPolicy
    {
        try {
            $policy = $this->policies->inForceOn($period->assignment_reference_date);
        } catch (InvalidArgumentException $exception) {
            Log::error('Scorecard policy configuration is invalid; scoring is stopped.', ['error' => $exception->getMessage()]);

            throw new BusinessRuleViolation('scoring_policy_invalid', 'Konfigurasi aturan scorecard tidak valid, sehingga perhitungan dihentikan. Hubungi administrator aplikasi.');
        }

        if ($policy === null) {
            throw new BusinessRuleViolation('scoring_policy_not_approved', 'Aturan scorecard (data terlambat, bobot KPI N/A, pembulatan, dan rating band) belum disetujui (DEC-004). Perhitungan skor resmi diblokir.');
        }

        return $policy;
    }

    private static function assertScorable(AssessmentPeriod $period, bool $correction = false): void
    {
        if ($correction) {
            if (! in_array($period->status, [PeriodStatus::Review, PeriodStatus::Finalized, PeriodStatus::Locked], true)) {
                throw new BusinessRuleViolation('period_not_scorable', 'Koreksi hanya berlaku pada periode Review, Finalized, atau Locked.');
            }

            return;
        }

        if (in_array($period->status, [PeriodStatus::Finalized, PeriodStatus::Locked], true)) {
            throw new BusinessRuleViolation('scorecard_final', 'Periode sudah final. Skor hanya dapat berubah melalui proses koreksi yang disetujui.');
        }

        if (! in_array($period->status, [PeriodStatus::InProgress, PeriodStatus::Review], true)) {
            throw new BusinessRuleViolation('period_not_scorable', 'Skor dihitung setelah periode masuk tahap In progress.');
        }
    }

    /**
     * A version's meaning never changes: stored calculations reference it.
     */
    private function assertPolicyUnchanged(ScorecardPolicy $policy): void
    {
        $changed = ScorecardCalculation::query()
            ->where('policy_key', $policy->key)
            ->where('policy_version', $policy->version)
            ->where('policy_hash', '<>', $policy->hash())
            ->exists();

        if ($changed) {
            throw new BusinessRuleViolation('scoring_policy_changed', "Isi aturan scorecard {$policy->reference()} berbeda dari yang dipakai perhitungan sebelumnya. Versi yang sudah dipakai tidak boleh diubah; terbitkan versi baru.");
        }
    }

    /**
     * @param  list<int>|null  $assignmentIds
     * @return Collection<int, PeriodAssignment>
     */
    private function assignments(AssessmentPeriod $period, ScoreAccess $access, ?array $assignmentIds): Collection
    {
        $query = PeriodAssignment::query()
            ->where('assessment_period_id', $period->id)
            ->where('coverage', Coverage::Covered->value);

        if ($assignmentIds !== null) {
            $query->whereIn('id', $assignmentIds === [] ? [0] : $assignmentIds);
        }

        $access->apply($query);

        // One calculation at a time per subject keeps sequence numbers gapless.
        $assignments = $query->orderBy('id')->lockForUpdate()->get();

        if ($assignments->isEmpty()) {
            throw new BusinessRuleViolation('nothing_to_calculate', 'Tidak ada scorecard dalam scope Anda yang dapat dihitung pada periode ini.');
        }

        return $assignments;
    }

    /**
     * @param  Collection<int, PeriodAssignment>  $assignments
     * @return array<int, ScorecardCalculation> assignment id => latest calculation
     */
    private function latestCalculations(Collection $assignments): array
    {
        return ScorecardCalculation::query()
            ->whereIn('period_assignment_id', $assignments->modelKeys())
            ->whereNotExists(fn (QueryBuilder $newer) => $newer->from('scorecard_calculations as newer')
                ->whereColumn('newer.period_assignment_id', 'scorecard_calculations.period_assignment_id')
                ->whereColumn('newer.sequence_number', '>', 'scorecard_calculations.sequence_number'))
            ->get()
            ->keyBy('period_assignment_id')
            ->all();
    }

    /**
     * @param  array<string, int>  $targetIds  target public id => id
     */
    private function store(
        Actor $actor,
        AssessmentPeriod $period,
        PeriodAssignment $assignment,
        ?ScorecardCalculation $previous,
        ScorecardPolicy $policy,
        ScorecardInput $input,
        string $fingerprint,
        ScorecardResult $result,
        string $reason,
        array $targetIds,
    ): ScorecardCalculation {
        $calculation = ScorecardCalculation::query()->create([
            'assessment_period_id' => $period->id,
            'period_assignment_id' => $assignment->id,
            'sequence_number' => ($previous->sequence_number ?? 0) + 1,
            'previous_calculation_id' => $previous?->id,
            'engine_version' => ScorecardCalculator::ENGINE_VERSION,
            'policy_key' => $policy->key,
            'policy_version' => $policy->version,
            'policy_signoff_reference' => $policy->signoffReference,
            'policy_hash' => $policy->hash(),
            'policy' => $policy->toArray(),
            'input_hash' => $fingerprint,
            'inputs' => $input->toArray(),
            'status' => $result->status,
            'blockers' => $result->blockers,
            'assigned_weight' => Exact::fixed($result->assignedWeight),
            'counted_weight' => Exact::fixed($result->countedWeight),
            'excluded_weight' => Exact::fixed($result->excludedWeight),
            'contribution_total' => $result->contributionTotal === null ? null : Exact::fixed($result->contributionTotal),
            'final_score' => $result->finalScore === null ? null : Exact::fixed($result->finalScore),
            'rating_key' => $result->rating?->key,
            'rating_label' => $result->rating?->label,
            'reason' => $reason,
            'calculated_by_user_id' => $actor->userId,
            'calculated_at' => CarbonImmutable::now(),
        ]);

        foreach ($result->lines as $index => $line) {
            ScorecardCalculationLine::query()->create(self::lineRow($calculation, $index + 1, $line, $targetIds));
        }

        $this->audit->record(new AuditEntry(
            action: 'scorecard.calculated',
            entityType: 'scorecard',
            entityId: $assignment->public_id,
            before: $previous === null ? null : ['calculation' => $previous->public_id, 'sequence' => $previous->sequence_number, 'status' => $previous->status->value, 'final_score' => $previous->final_score, 'rating' => $previous->rating_key],
            after: ['calculation' => $calculation->public_id, 'sequence' => $calculation->sequence_number, 'status' => $result->status->value, 'final_score' => $calculation->final_score, 'rating' => $calculation->rating_key, 'policy' => $policy->reference(), 'input_hash' => $fingerprint],
            reason: $reason,
            context: $actor->audit,
        ));

        $this->outbox->publish(new OutboxEvent(
            eventType: 'ScoreCalculated',
            eventVersion: 1,
            aggregateType: 'scorecard',
            aggregateId: $assignment->public_id,
            payload: [
                'period_id' => $period->public_id,
                'calculation_id' => $calculation->public_id,
                'sequence' => $calculation->sequence_number,
                'status' => $result->status->value,
                'policy' => $policy->reference(),
                'engine_version' => ScorecardCalculator::ENGINE_VERSION,
            ],
            deduplicationKey: "scorecalculated:{$calculation->public_id}",
        ));

        return $calculation;
    }

    /**
     * @param  array<string, int>  $targetIds
     * @return array<string, mixed>
     */
    private static function lineRow(ScorecardCalculation $calculation, int $number, KpiLineResult $line, array $targetIds): array
    {
        $input = $line->input;

        return [
            'scorecard_calculation_id' => $calculation->id,
            'line_number' => $number,
            'period_target_id' => $input->targetReference === null ? null : ($targetIds[$input->targetReference] ?? null),
            'kpi_definition_version_id' => $input->definitionVersionId,
            'kpi_code' => $input->kpiCode,
            'kpi_name' => $input->kpiName,
            'component' => $input->component,
            'weight' => $input->weight,
            'is_mandatory' => $input->isMandatory,
            'strategy_key' => $input->strategyKey,
            'strategy_version' => $input->strategyVersion,
            'aggregation' => $input->aggregation,
            'data_status' => $line->status,
            'note' => $line->note,
            'target_value' => $input->targetValue,
            'measured_value' => Exact::describeNullable($line->measured),
            'achievement' => Exact::describeNullable($line->achievement),
            'kpi_score' => $line->score === null ? null : Exact::fixed($line->score),
            'contribution' => $line->contribution === null ? null : Exact::fixed($line->contribution),
            'details' => $line->toArray(),
        ];
    }
}
