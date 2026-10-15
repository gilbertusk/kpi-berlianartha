<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Modules\Operations\Domain\CostComponent;
use App\Modules\Operations\Domain\CostingComparison;
use App\Modules\Reporting\Application\ReportContext;
use App\Modules\Reporting\Domain\Classification;

/**
 * Estimated versus actual per costing, version, production, and component.
 * Rows are ordered by costing and version so versions never interleave.
 */
final class CostingComparisonReport extends OperationalRecordReport
{
    protected function typeKey(): string
    {
        return 'costing_comparison';
    }

    public function key(): string
    {
        return 'costing_comparisons';
    }

    public function title(): string
    {
        return 'Costing vs aktual produksi';
    }

    public function description(): string
    {
        return 'Estimasi costing terhadap aktual produksi per versi asumsi dan komponen, dengan selisih dan selisih relatif.';
    }

    public function columns(): array
    {
        return ['Team', 'Tanggal', 'Referensi costing', 'Versi asumsi', 'Referensi produksi', 'BOM', 'Routing', 'Komponen', 'Satuan', 'Estimasi', 'Aktual produksi', 'Selisih (aktual − estimasi)', 'Selisih relatif (%)', 'Sumber', 'Dicatat oleh'];
    }

    public function filters(): array
    {
        return ['unit', 'from', 'to'];
    }

    public function classification(): Classification
    {
        return Classification::Restricted;
    }

    public function rows(ReportContext $context): iterable
    {
        $records = $this->records($context)
            ->with(['unit', 'recordedBy'])
            ->orderBy('source_reference')
            ->orderBy('id')
            ->lazy(500);

        foreach ($records as $record) {
            $values = $record->values();
            $comparison = CostingComparison::of((string) $values['estimated'], (string) $values['actual']);

            yield [
                $record->unit->code, $record->occurred_on, (string) $values['costing_reference'], (string) $values['costing_version'],
                (string) $values['production_reference'], self::text($values['bom_reference'] ?? null), self::text($values['routing_reference'] ?? null),
                CostComponent::tryFrom((string) $values['component'])?->label(), (string) $values['measure_unit'],
                $comparison->estimated, $comparison->actual, $comparison->variance, $comparison->variancePercent ?? 'estimasi nol',
                $record->source_system, $record->recordedBy->name,
            ];
        }
    }

    private static function text(string|int|null $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
