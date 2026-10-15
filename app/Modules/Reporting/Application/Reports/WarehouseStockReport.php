<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Domain\LocationCategory;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionRecord;
use App\Modules\Reporting\Application\ReportContext;

/**
 * Stock per warehouse location category as the source reported it on each
 * as-of date: a reference snapshot, not an inventory ledger.
 */
final class WarehouseStockReport extends ProductionBatchReport
{
    public function key(): string
    {
        return 'warehouse_stock';
    }

    public function title(): string
    {
        return 'Stok warehouse per kategori lokasi';
    }

    public function description(): string
    {
        return 'Snapshot stok dari sistem sumber per tanggal as-of: Raw Material, Work in Process, Finished Goods, Reject/Quarantine, Return, dan Scrap.';
    }

    public function columns(): array
    {
        return ['Team', 'Tanggal as-of', 'Kategori lokasi', 'Lokasi', 'Item', 'Lot', 'Satuan', 'Kuantitas', 'Sumber', 'Versi batch', 'Diekstrak'];
    }

    public function filters(): array
    {
        return ['unit', 'from', 'to', 'status'];
    }

    public function statuses(): array
    {
        $options = [];

        foreach (LocationCategory::cases() as $category) {
            $options[$category->value] = $category->label();
        }

        return $options;
    }

    public function rows(ReportContext $context): iterable
    {
        foreach ($this->batches($context, [BatchKind::Warehouse])->orderBy('coverage_date')->orderBy('id')->get() as $batch) {
            $records = ProductionRecord::query()->where('production_batch_id', $batch->id)
                ->when($context->filters->status !== null, fn ($query) => $query->where('location_category', $context->filters->status))
                ->orderBy('id')->lazyById(1000);

            foreach ($records as $record) {
                yield [
                    $batch->unit->code, $batch->coverage_date, LocationCategory::tryFrom((string) $record->location_category)?->label(), $record->detail('location_code'),
                    $record->product_code, $record->detail('lot'), $record->unit, $record->quantity, $batch->source_system, 'v'.$batch->version, $this->local($batch->source_extracted_at),
                ];
            }
        }
    }
}
