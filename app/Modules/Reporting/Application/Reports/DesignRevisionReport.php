<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Modules\Operations\Domain\RevisionCategory;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Domain\Classification;

/**
 * Every live Design revision with its category and whether it counts as an
 * internal error.
 */
final class DesignRevisionReport extends OperationalRecordReport
{
    protected function typeKey(): string
    {
        return 'design_revision';
    }

    public function key(): string
    {
        return 'design_revisions';
    }

    public function title(): string
    {
        return 'Revisi Design';
    }

    public function description(): string
    {
        return 'Setiap revisi artwork per team dengan kategori penyebab dan apakah dihitung sebagai kesalahan internal.';
    }

    public function columns(): array
    {
        return ['Team', 'Tanggal', 'Referensi pekerjaan', 'Revisi ke-', 'Kategori', 'Kesalahan internal', 'Keterangan', 'KPI terkait', 'Sumber', 'Referensi sumber', 'Dicatat oleh'];
    }

    public function filters(): array
    {
        return ['unit', 'from', 'to', 'status'];
    }

    public function statuses(): array
    {
        return RevisionCategory::options();
    }

    public function classification(): Classification
    {
        return Classification::Internal;
    }

    public function rows(ReportContext $context): iterable
    {
        $records = $this->records($context)
            ->with(['unit', 'recordedBy', 'kpiDefinition'])
            ->when($context->filters->status !== null, fn ($query) => $query->where('details->category', $context->filters->status))
            ->orderBy('id')
            ->lazyById(500);

        foreach ($records as $record) {
            $values = $record->values();
            $category = RevisionCategory::tryFrom((string) ($values['category'] ?? ''));

            yield [
                $record->unit->code, $record->occurred_on, (string) ($values['job_reference'] ?? ''), (string) ($values['revision_number'] ?? ''),
                $category?->label(), $category?->isInternal() ? 'ya' : 'tidak', (string) ($values['description'] ?? ''), $record->kpiDefinition?->code,
                $record->source_system, $record->source_reference, $record->recordedBy->name,
            ];
        }
    }
}
