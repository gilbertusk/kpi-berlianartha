<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;

/**
 * Every stored calculation of the period, not only the latest: sequence,
 * policy and engine version, fingerprints, result, and reason. This is the
 * reconciliation trail of how a score came to be.
 */
final class CalculationReport extends ScorecardBasedReport
{
    public function key(): string
    {
        return 'calculations';
    }

    public function title(): string
    {
        return 'Rekonsiliasi perhitungan';
    }

    public function description(): string
    {
        return 'Seluruh perhitungan tersimpan per subjek: urutan, aturan dan versi mesin, hash input, hasil, dan alasan.';
    }

    public function columns(): array
    {
        return [
            'Periode', 'Jenis subjek', 'Kode subjek', 'Nama subjek', 'Unit', 'Perhitungan #', 'Dihitung pada (UTC)', 'Status', 'Penahan',
            'Aturan scorecard', 'Referensi sign-off', 'Versi mesin', 'Policy hash', 'Input hash', 'Bobot dinilai', 'Bobot dikecualikan',
            'Σ kontribusi', 'Skor akhir', 'Rating', 'Alasan', 'Dihitung oleh',
        ];
    }

    public function filters(): array
    {
        return ['period', 'unit', 'level', 'employee', 'status'];
    }

    public function statuses(): array
    {
        return ['complete' => 'Lengkap', 'incomplete' => 'Belum lengkap'];
    }

    public function classification(): Classification
    {
        return Classification::Restricted;
    }

    public function rows(ReportContext $context): iterable
    {
        $calculations = ScorecardCalculation::query()
            ->with(['assignment', 'calculatedBy'])
            ->whereIn('period_assignment_id', $this->assignments($context)->select('period_assignments.id'))
            ->when($context->filters->status !== null, fn ($query) => $query->where('status', $context->filters->status))
            ->orderBy('id')
            ->lazyById(self::CHUNK);

        foreach ($calculations as $calculation) {
            $assignment = $calculation->assignment;

            yield [
                $context->period?->code, $assignment->subject_type->value, $assignment->subject_code, $assignment->subject_name, $assignment->unit_code,
                (string) $calculation->sequence_number, $calculation->calculated_at->format('Y-m-d H:i:s'), $calculation->status->label(),
                implode(' ', $calculation->blockers), $calculation->policyReference(), $calculation->policy_signoff_reference,
                (string) $calculation->engine_version, $calculation->policy_hash, $calculation->input_hash, $calculation->counted_weight,
                $calculation->excluded_weight, $calculation->contribution_total, $calculation->final_score, $calculation->rating_label,
                $calculation->reason, $calculation->calculatedBy?->name,
            ];
        }
    }
}
