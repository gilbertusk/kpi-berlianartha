<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Scoring\Domain\ScorecardStatus;
use Illuminate\Support\Facades\DB;

/**
 * Number of scorecards per rating and unit, from the latest complete
 * calculation of each subject (the calculated rating, before calibration).
 */
final class RatingDistributionReport extends ScorecardBasedReport
{
    public function key(): string
    {
        return 'rating_distribution';
    }

    public function title(): string
    {
        return 'Distribusi rating';
    }

    public function description(): string
    {
        return 'Jumlah scorecard per rating hasil perhitungan terakhir, per unit dan jenis subjek.';
    }

    public function columns(): array
    {
        return ['Periode', 'Unit', 'Jenis subjek', 'Rating', 'Jumlah scorecard'];
    }

    public function filters(): array
    {
        return ['period', 'unit', 'level'];
    }

    public function classification(): Classification
    {
        return Classification::Internal;
    }

    public function rows(ReportContext $context): iterable
    {
        $rows = DB::table('scorecard_calculations as c')
            ->joinSub($this->assignments($context)->select(['period_assignments.id', 'period_assignments.unit_code', 'period_assignments.subject_type']), 'a', 'a.id', '=', 'c.period_assignment_id')
            ->where('c.status', ScorecardStatus::Complete->value)
            ->whereNotExists(fn ($newer) => $newer
                ->from('scorecard_calculations as newer')
                ->whereColumn('newer.period_assignment_id', 'c.period_assignment_id')
                ->whereColumn('newer.sequence_number', '>', 'c.sequence_number'))
            ->groupBy('a.unit_code', 'a.subject_type', 'c.rating_key', 'c.rating_label')
            ->orderBy('a.unit_code')
            ->orderBy('a.subject_type')
            ->orderBy('c.rating_key')
            ->selectRaw('a.unit_code, a.subject_type, c.rating_label, COUNT(*) AS total')
            ->get();

        foreach ($rows as $row) {
            yield [$context->period?->code, (string) $row->unit_code, (string) $row->subject_type, (string) $row->rating_label, (string) $row->total];
        }
    }
}
