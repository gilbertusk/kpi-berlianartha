<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionRecord;
use App\Modules\Reporting\Application\ReportContext;

/**
 * Every row of the current production, packing, and delivery batches with
 * its dimensions and source reference. Sums and rates are left to approved
 * KPI formulas; operators are listed as the source gave them, never rated.
 */
final class ProductionActualsReport extends ProductionBatchReport
{
    public function key(): string
    {
        return 'production_actuals';
    }

    public function title(): string
    {
        return 'Aktual produksi, packing, pengiriman, dan inspeksi';
    }

    public function description(): string
    {
        return 'Baris versi berlaku per tanggal operasional, shift, mesin, work order, produk, satuan, reason code, dan referensi batch sumber.';
    }

    public function columns(): array
    {
        return ['Team', 'Tanggal', 'Shift', 'Jenis', 'Event', 'Work order', 'Produk', 'Mesin', 'Waktu', 'Satuan', 'Kuantitas', 'Reject', 'Rework/repack', 'Downtime (menit)', 'Reason code', 'Defect code', 'Sumber', 'Versi batch', 'Diekstrak'];
    }

    public function filters(): array
    {
        return ['unit', 'from', 'to', 'status'];
    }

    public function statuses(): array
    {
        return [
            ProductionRecord::OUTPUT => 'Output',
            ProductionRecord::DOWNTIME => 'Downtime',
            ProductionRecord::WASTE => 'Waste',
            ProductionRecord::PACKING => 'Packing',
            ProductionRecord::DELIVERY => 'Pengiriman',
            ProductionRecord::INSPECTION => 'Inspeksi QC',
        ];
    }

    public function rows(ReportContext $context): iterable
    {
        foreach ($this->batches($context, [BatchKind::Production, BatchKind::Packing, BatchKind::Delivery, BatchKind::Inspection])->orderBy('coverage_date')->orderBy('id')->get() as $batch) {
            $records = ProductionRecord::query()->where('production_batch_id', $batch->id)
                ->when($context->filters->status !== null, fn ($query) => $query->where('record_type', $context->filters->status))
                ->orderBy('id')->lazyById(1000);

            foreach ($records as $record) {
                yield [
                    $batch->unit->code, $record->operational_date, $record->shift_code, $record->record_type, $record->detail('delivery_number') ?? $record->event_key,
                    $record->work_order, $record->product_code, $record->machine_code, $this->local($record->started_at), $record->unit, $record->quantity,
                    $record->reject_quantity, $record->rework_quantity, $record->downtime_minutes === null ? null : (string) $record->downtime_minutes,
                    $record->reason_code, $record->defect_code, $batch->source_system, 'v'.$batch->version, $this->local($batch->source_extracted_at),
                ];
            }
        }
    }
}
