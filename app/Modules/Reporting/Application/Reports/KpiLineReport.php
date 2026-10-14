<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Models\User;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Application\ReportSupport;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculationLine;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Brick\Math\BigDecimal;

/**
 * Target versus actual per KPI, from the lines of each subject's latest
 * calculation, with the KPI score of the previous period of the same type
 * (trend) and the red-KPI flag once a threshold is approved.
 */
final class KpiLineReport extends ScorecardBasedReport
{
    public function key(): string
    {
        return 'kpi_lines';
    }

    public function title(): string
    {
        return 'Target versus aktual, tren, dan KPI merah';
    }

    public function description(): string
    {
        return 'Baris KPI perhitungan terakhir: target, nilai terukur, skor, skor periode sebelumnya, dan tanda KPI merah bila ambang sudah disetujui.';
    }

    public function columns(): array
    {
        return [
            'Periode', 'Jenis subjek', 'Kode subjek', 'Nama subjek', 'Unit', 'Kode KPI', 'Nama KPI', 'Bobot', 'Target', 'Nilai terukur',
            'Pencapaian', 'Status data', 'Skor KPI', 'Kontribusi', 'Periode sebelumnya', 'Skor KPI periode sebelumnya', 'Di bawah ambang',
        ];
    }

    public function filters(): array
    {
        return ['period', 'unit', 'level', 'employee', 'kpi', 'red_only'];
    }

    public function classification(): Classification
    {
        return Classification::Restricted;
    }

    public function authorize(User $user, ReportFilters $filters): ReportContext
    {
        if ($filters->redOnly && ReportSupport::redThreshold() === null) {
            throw new BusinessRuleViolation('threshold_not_approved', 'Ambang KPI merah belum disetujui (DEC-004), sehingga filter KPI merah belum tersedia.');
        }

        return parent::authorize($user, $filters);
    }

    public function rows(ReportContext $context): iterable
    {
        $period = $context->period;
        $threshold = ReportSupport::redThreshold();
        $limit = $threshold === null ? null : BigDecimal::of($threshold);
        $previousPeriod = $period === null ? null : self::previousPeriod($period);

        foreach ($this->assignments($context)->orderBy('id')->lazyById(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
            $calculations = self::latestCalculations(array_values($chunk->map(static fn (PeriodAssignment $assignment): int => $assignment->id)->all()));
            $calculationIds = array_map(static fn ($calculation): int => $calculation->id, array_values($calculations));
            $lines = ScorecardCalculationLine::query()
                ->whereIn('scorecard_calculation_id', $calculationIds)
                ->when($context->filters->kpi !== null, fn ($query) => $query->where('kpi_code', $context->filters->kpi))
                ->orderBy('scorecard_calculation_id')
                ->orderBy('line_number')
                ->get()
                ->groupBy('scorecard_calculation_id');
            $earlier = $previousPeriod === null ? [] : self::previousScores($previousPeriod, $chunk->pluck('subject_key')->all());

            foreach ($chunk as $assignment) {
                $calculation = $calculations[$assignment->id] ?? null;

                if ($calculation === null) {
                    continue;
                }

                foreach ($lines->get($calculation->id, []) as $line) {
                    $red = $limit !== null && $line->kpi_score !== null && BigDecimal::of($line->kpi_score)->isLessThan($limit);

                    if ($context->filters->redOnly && ! $red) {
                        continue;
                    }

                    yield [
                        $period?->code, $assignment->subject_type->value, $assignment->subject_code, $assignment->subject_name, $assignment->unit_code,
                        $line->kpi_code, $line->kpi_name, $line->weight, $line->target_value, $line->measured_value, $line->achievement,
                        $line->data_status->label(), $line->kpi_score, $line->contribution,
                        $previousPeriod?->code, $earlier[$assignment->subject_key][$line->kpi_code] ?? null,
                        $limit === null ? 'ambang belum disetujui' : ($red ? 'ya' : 'tidak'),
                    ];
                }
            }
        }
    }

    private static function previousPeriod(AssessmentPeriod $period): ?AssessmentPeriod
    {
        return AssessmentPeriod::query()
            ->where('period_type', $period->period_type->value)
            ->where('organization_unit_id', $period->organization_unit_id)
            ->where('ends_on', '<', $period->starts_on)
            ->whereIn('status', [PeriodStatus::Review->value, PeriodStatus::Finalized->value, PeriodStatus::Locked->value])
            ->orderByDesc('ends_on')
            ->first();
    }

    /**
     * @param  array<int, mixed>  $subjectKeys
     * @return array<string, array<string, string|null>> subject key => KPI code => score
     */
    private static function previousScores(AssessmentPeriod $previous, array $subjectKeys): array
    {
        $assignments = PeriodAssignment::query()
            ->where('assessment_period_id', $previous->id)
            ->whereIn('subject_key', $subjectKeys)
            ->pluck('subject_key', 'id');

        $calculations = self::latestCalculations(array_values(array_map(intval(...), $assignments->keys()->all())));
        $byCalculation = [];

        foreach ($calculations as $assignmentId => $calculation) {
            $byCalculation[$calculation->id] = (string) $assignments[$assignmentId];
        }

        $scores = [];

        foreach (ScorecardCalculationLine::query()->whereIn('scorecard_calculation_id', array_keys($byCalculation))->get(['scorecard_calculation_id', 'kpi_code', 'kpi_score']) as $line) {
            $scores[$byCalculation[$line->scorecard_calculation_id]][$line->kpi_code] = $line->kpi_score;
        }

        return $scores;
    }
}
