<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Application;

use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Scoring\Domain\KpiDataStatus;
use App\Modules\Scoring\Domain\ScorecardCalculator;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Target\Application\TargetCsv;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * Scoped reads for scorecards, the reconciliation list, and its export.
 * Scope filtering happens in SQL through {@see ScoreAccess}.
 */
final readonly class ScorecardQuery
{
    private const PER_PAGE = 25;

    /** @var array<string, string> */
    public const STATES = [
        'complete' => 'Lengkap',
        'incomplete' => 'Belum lengkap',
        'not_calculated' => 'Belum dihitung',
        'not_covered' => 'Tidak tercakup template',
    ];

    public const EXPORT_HEADER = [
        'period_code', 'subject_type', 'subject_code', 'subject_name', 'unit_code', 'template', 'calculation_sequence',
        'calculated_at_utc', 'scorecard_status', 'blockers', 'policy', 'policy_signoff', 'engine_version', 'input_hash',
        'line', 'kpi_code', 'kpi_name', 'component', 'weight', 'strategy', 'aggregation', 'data_status', 'note',
        'target_value', 'measured_value', 'achievement', 'kpi_score', 'contribution', 'contribution_total', 'final_score', 'rating',
    ];

    public function __construct(
        private ScorecardPolicyCatalog $policies,
        private ScorecardInputLoader $inputs,
    ) {}

    /**
     * @return LengthAwarePaginator<int, ScorecardRow>
     */
    public function rows(AssessmentPeriod $period, ScoreAccess $access, ?string $unitCode, ?string $state, string $search, int $page): LengthAwarePaginator
    {
        $query = $this->base($period, $access);

        if ($unitCode !== null) {
            $query->where('unit_code', $unitCode);
        }

        match ($state) {
            'not_covered' => $query->where('coverage', '<>', Coverage::Covered->value),
            'not_calculated' => $query->where('coverage', Coverage::Covered->value)->whereNotExists(fn (QueryBuilder $calculations) => $calculations
                ->from('scorecard_calculations')->whereColumn('scorecard_calculations.period_assignment_id', 'period_assignments.id')),
            'complete', 'incomplete' => $query->whereExists(fn (QueryBuilder $latest) => self::latestWithStatus($latest, $state)),
            default => null,
        };

        $needle = mb_strtolower(trim($search));

        if ($needle !== '') {
            $pattern = '%'.addcslashes($needle, '%_\\').'%';
            $query->where(fn (Builder $match) => $match
                ->whereRaw('lower(subject_code) like ?', [$pattern])
                ->orWhereRaw('lower(subject_name) like ?', [$pattern])
                ->orWhereRaw('lower(unit_code) like ?', [$pattern]));
        }

        $assignments = $query->orderBy('unit_code')->orderBy('subject_type')->orderBy('subject_code')->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $latest = $this->latestFor(array_values(array_map(fn (PeriodAssignment $assignment): int => $assignment->id, $assignments->items())));

        return $assignments->through(fn (PeriodAssignment $assignment): ScorecardRow => new ScorecardRow($assignment, $latest[$assignment->id] ?? null));
    }

    /**
     * @return array{subjects: int, covered: int, not_calculated: int, complete: int, incomplete: int}
     */
    public function summary(AssessmentPeriod $period, ScoreAccess $access): array
    {
        $covered = fn (): Builder => $this->base($period, $access)->where('coverage', Coverage::Covered->value);

        return [
            'subjects' => $this->base($period, $access)->count(),
            'covered' => $covered()->count(),
            'not_calculated' => $covered()->whereNotExists(fn (QueryBuilder $calculations) => $calculations
                ->from('scorecard_calculations')->whereColumn('scorecard_calculations.period_assignment_id', 'period_assignments.id'))->count(),
            'complete' => $covered()->whereExists(fn (QueryBuilder $latest) => self::latestWithStatus($latest, 'complete'))->count(),
            'incomplete' => $covered()->whereExists(fn (QueryBuilder $latest) => self::latestWithStatus($latest, 'incomplete'))->count(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function unitOptions(AssessmentPeriod $period, ScoreAccess $access): array
    {
        return $this->base($period, $access)
            ->select(['unit_code', 'unit_name'])
            ->distinct()
            ->orderBy('unit_code')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->unit_code => "{$row->unit_code} — {$row->unit_name}"])
            ->all();
    }

    /**
     * A specific calculation of a subject, or its latest one.
     */
    public function calculation(PeriodAssignment $assignment, ?int $sequence = null): ?ScorecardCalculation
    {
        $query = ScorecardCalculation::query()->where('period_assignment_id', $assignment->id)->with(['lines', 'calculatedBy']);

        return $sequence === null
            ? $query->orderByDesc('sequence_number')->first()
            : $query->where('sequence_number', $sequence)->first();
    }

    /**
     * @return Collection<int, ScorecardCalculation>
     */
    public function history(PeriodAssignment $assignment): Collection
    {
        return ScorecardCalculation::query()
            ->where('period_assignment_id', $assignment->id)
            ->with('calculatedBy')
            ->orderByDesc('sequence_number')
            ->get();
    }

    /**
     * The fingerprint the subject would have if calculated now, to tell
     * whether its latest calculation is stale. Null when no approved policy
     * is in force or the subject is not covered.
     */
    public function currentFingerprint(AssessmentPeriod $period, PeriodAssignment $assignment): ?string
    {
        try {
            $policy = $this->policies->inForceOn($period->assignment_reference_date);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($policy === null || $assignment->coverage !== Coverage::Covered) {
            return null;
        }

        $input = $this->inputs->load($period, new Collection([$assignment]))[$assignment->id];

        return ScorecardCalculator::fingerprint($input, $policy);
    }

    /**
     * Reconciliation export: one row per KPI line of each subject's latest
     * calculation (one row for a subject without calculation). Text cells are
     * neutralized against spreadsheet formulas.
     *
     * @return Generator<int, list<string>>
     */
    public function exportRows(AssessmentPeriod $period, ScoreAccess $access): Generator
    {
        yield self::EXPORT_HEADER;

        foreach ($this->base($period, $access)->orderBy('id')->lazyById(200) as $assignment) {
            $calculation = $this->calculation($assignment);
            $subject = [
                $period->code, $assignment->subject_type->value, $assignment->subject_code, $assignment->subject_name, $assignment->unit_code,
                $assignment->template_code === null ? '' : "{$assignment->template_code}@{$assignment->template_version_number}",
            ];

            if ($calculation === null) {
                yield array_map(TargetCsv::cell(...), [...$subject, '', '', (new ScorecardRow($assignment, null))->state(), ...array_fill(0, 22, '')]);

                continue;
            }

            $head = [
                ...$subject, (string) $calculation->sequence_number, $calculation->calculated_at->format('Y-m-d H:i:s'),
                $calculation->status->value, implode(' ', $calculation->blockers), $calculation->policyReference(),
                $calculation->policy_signoff_reference, (string) $calculation->engine_version, $calculation->input_hash,
            ];
            $tail = [(string) $calculation->contribution_total, (string) $calculation->final_score, (string) $calculation->rating_label];

            foreach ($calculation->lines as $line) {
                yield array_map(TargetCsv::cell(...), [
                    ...$head, (string) $line->line_number, $line->kpi_code, $line->kpi_name, (string) $line->component, $line->weight,
                    ($line->strategy_key ?? '').'@'.($line->strategy_version ?? ''), (string) $line->aggregation, $line->data_status->value,
                    (string) $line->note, (string) $line->target_value, (string) $line->measured_value, (string) $line->achievement,
                    (string) $line->kpi_score, (string) $line->contribution, ...$tail,
                ]);
            }
        }
    }

    /**
     * @return Builder<PeriodAssignment>
     */
    public function base(AssessmentPeriod $period, ScoreAccess $access): Builder
    {
        $query = PeriodAssignment::query()->where('assessment_period_id', $period->id);
        $access->apply($query);

        return $query;
    }

    /**
     * @param  list<int>  $assignmentIds
     * @return array<int, ScorecardCalculation>
     */
    private function latestFor(array $assignmentIds): array
    {
        return ScorecardCalculation::query()
            ->whereIn('period_assignment_id', $assignmentIds === [] ? [0] : $assignmentIds)
            ->whereNotExists(fn (QueryBuilder $newer) => self::newer($newer))
            ->get()
            ->keyBy('period_assignment_id')
            ->all();
    }

    private static function latestWithStatus(QueryBuilder $latest, string $status): void
    {
        $latest->from('scorecard_calculations')
            ->whereColumn('scorecard_calculations.period_assignment_id', 'period_assignments.id')
            ->where('scorecard_calculations.status', $status)
            ->whereNotExists(fn (QueryBuilder $newer) => self::newer($newer));
    }

    private static function newer(QueryBuilder $newer): void
    {
        $newer->from('scorecard_calculations as newer')
            ->whereColumn('newer.period_assignment_id', 'scorecard_calculations.period_assignment_id')
            ->whereColumn('newer.sequence_number', '>', 'scorecard_calculations.sequence_number');
    }

    /**
     * Data status labels for the page legend.
     *
     * @return array<string, string>
     */
    public static function dataStatuses(): array
    {
        $labels = [];

        foreach (KpiDataStatus::cases() as $status) {
            $labels[$status->value] = $status->label();
        }

        return $labels;
    }
}
