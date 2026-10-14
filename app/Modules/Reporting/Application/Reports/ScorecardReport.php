<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Workflow\Application\WorkflowService;
use App\Modules\Workflow\Infrastructure\Persistence\Models\ScorecardReview;

/**
 * Scorecard per subject at employee, team, department, or company level:
 * the latest calculation, the score that counts after calibration, and the
 * approval state. One row per subject, the same rows as the reconciliation
 * list on the scorecard page.
 */
final class ScorecardReport extends ScorecardBasedReport
{
    public function key(): string
    {
        return 'scorecards';
    }

    public function title(): string
    {
        return 'Scorecard per subjek';
    }

    public function description(): string
    {
        return 'Skor karyawan, team, department, dan perusahaan: perhitungan terakhir, skor setelah kalibrasi, dan status approval.';
    }

    public function columns(): array
    {
        return [
            'Periode', 'Jenis subjek', 'Kode subjek', 'Nama subjek', 'Unit', 'Tipe unit', 'Template', 'Status perhitungan', 'Perhitungan #',
            'Skor hasil perhitungan', 'Rating hasil perhitungan', 'Skor berlaku', 'Rating berlaku', 'Dikalibrasi', 'Status approval', 'Putaran',
            'Aturan scorecard', 'Input hash',
        ];
    }

    public function filters(): array
    {
        return ['period', 'unit', 'level', 'employee', 'status'];
    }

    public function statuses(): array
    {
        return ['complete' => 'Lengkap', 'incomplete' => 'Belum lengkap', 'not_calculated' => 'Belum dihitung', 'not_covered' => 'Tanpa template'];
    }

    public function classification(): Classification
    {
        return Classification::Restricted;
    }

    public function rows(ReportContext $context): iterable
    {
        $period = $context->period;

        foreach ($this->assignments($context)->orderBy('id')->lazyById(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
            $ids = array_values($chunk->map(static fn (PeriodAssignment $assignment): int => $assignment->id)->all());
            $calculations = self::latestCalculations($ids);
            $reviews = ScorecardReview::query()->with('calibrations')->whereIn('period_assignment_id', $ids)->get()->keyBy('period_assignment_id');

            foreach ($chunk as $assignment) {
                $calculation = $calculations[$assignment->id] ?? null;
                $state = match (true) {
                    $assignment->coverage !== Coverage::Covered => 'not_covered',
                    $calculation === null => 'not_calculated',
                    default => $calculation->status->value,
                };

                if ($context->filters->status !== null && $context->filters->status !== $state) {
                    continue;
                }

                /** @var ScorecardReview|null $review */
                $review = $reviews->get($assignment->id);
                $effective = $calculation === null ? null
                    : ($review !== null && $review->scorecard_calculation_id === $calculation->id
                        ? WorkflowService::effectiveScore($review, $calculation)
                        : ['score' => $calculation->final_score, 'rating_label' => $calculation->rating_label, 'adjusted' => false]);

                yield [
                    $period?->code, $assignment->subject_type->value, $assignment->subject_code, $assignment->subject_name,
                    $assignment->unit_code, $assignment->unit_type,
                    $assignment->template_code === null ? null : "{$assignment->template_code}@{$assignment->template_version_number}",
                    $this->statuses()[$state], $calculation === null ? null : (string) $calculation->sequence_number,
                    $calculation?->final_score, $calculation?->rating_label,
                    $effective['score'] ?? null, $effective['rating_label'] ?? null,
                    $effective === null ? null : ($effective['adjusted'] ? 'ya' : 'tidak'),
                    $review?->state->label(), $review === null ? null : (string) $review->round,
                    $calculation?->policyReference(), $calculation?->input_hash,
                ];
            }
        }
    }
}
