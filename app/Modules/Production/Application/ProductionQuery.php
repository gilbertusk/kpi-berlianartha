<?php

declare(strict_types=1);

namespace App\Modules\Production\Application;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Domain\ShiftCalendars;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionRecord;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Config;

/**
 * Totals, reconciliation, and freshness of production batches. Only sums and
 * counts: no output rate, yield, OEE, utilization, or attainment is computed
 * until its formula, denominator, planned downtime, and unit conversion are
 * approved. Quantities in different units are never added together.
 */
final class ProductionQuery
{
    public static function calendars(): ShiftCalendars
    {
        $config = Config::get('production.shift_calendars', []);

        return ShiftCalendars::fromArray(is_array($config) ? $config : []);
    }

    /**
     * Totals of a batch per unit of measure, added in PHP: SQLite sums
     * decimals as floats.
     *
     * @return array{quantity: array<string, string>, reject: array<string, string>, rework: array<string, string>, waste: array<string, string>, downtime_minutes: int, rows: array<string, int>}
     */
    public static function totals(ProductionBatch $batch): array
    {
        $primary = self::primaryType($batch->kind);
        $sums = ['quantity' => [], 'reject' => [], 'rework' => [], 'waste' => []];
        $rows = [];
        $downtime = 0;

        foreach (ProductionRecord::query()->where('production_batch_id', $batch->id)->toBase()->select(['id', 'record_type', 'unit', 'quantity', 'reject_quantity', 'rework_quantity', 'downtime_minutes'])->lazyById(1000) as $row) {
            $type = (string) $row->record_type;
            $unit = (string) $row->unit;
            $rows[$type] = ($rows[$type] ?? 0) + 1;
            $downtime += (int) $row->downtime_minutes;

            if ($type === $primary) {
                $sums['quantity'][$unit] = self::add($sums['quantity'][$unit] ?? null, $row->quantity);
                $sums['reject'][$unit] = self::add($sums['reject'][$unit] ?? null, $row->reject_quantity);
                $sums['rework'][$unit] = self::add($sums['rework'][$unit] ?? null, $row->rework_quantity);
            } elseif ($type === ProductionRecord::WASTE) {
                $sums['waste'][$unit] = self::add($sums['waste'][$unit] ?? null, $row->quantity);
            }
        }

        $format = static fn (array $values): array => array_map(static fn (BigDecimal $value): string => (string) $value->toScale(3), $values);
        ksort($rows);

        return [
            'quantity' => $format($sums['quantity']),
            'reject' => $format($sums['reject']),
            'rework' => $format($sums['rework']),
            'waste' => $format($sums['waste']),
            'downtime_minutes' => $downtime,
            'rows' => $rows,
        ];
    }

    /**
     * Output per shift, machine, and unit of a production batch, and the
     * check that the breakdown adds up to the batch totals.
     *
     * @return array{rows: list<array{shift: string, machine: string, unit: string, good: string, reject: string, rework: string, downtime_minutes: int}>, reconciles: bool}
     */
    public static function breakdown(ProductionBatch $batch): array
    {
        $groups = [];

        foreach (ProductionRecord::query()->where('production_batch_id', $batch->id)->whereIn('record_type', [ProductionRecord::OUTPUT, ProductionRecord::DOWNTIME])->orderBy('id')->lazyById(1000) as $record) {
            $key = implode('|', [(string) $record->shift_code, (string) $record->machine_code, (string) $record->unit]);
            $group = $groups[$key] ?? ['shift' => (string) $record->shift_code, 'machine' => (string) $record->machine_code, 'unit' => (string) $record->unit, 'good' => BigDecimal::zero(), 'reject' => BigDecimal::zero(), 'rework' => BigDecimal::zero(), 'downtime_minutes' => 0];

            if ($record->record_type === ProductionRecord::OUTPUT) {
                $group['good'] = $group['good']->plus((string) $record->quantity);
                $group['reject'] = $group['reject']->plus((string) $record->reject_quantity);
                $group['rework'] = $group['rework']->plus((string) $record->rework_quantity);
            } else {
                $group['downtime_minutes'] += (int) $record->downtime_minutes;
            }

            $groups[$key] = $group;
        }

        ksort($groups);
        $rows = [];
        $check = ['good' => [], 'reject' => [], 'rework' => []];

        foreach ($groups as $group) {
            foreach (['good', 'reject', 'rework'] as $measure) {
                if ($group['unit'] !== '') {
                    $check[$measure][$group['unit']] = self::add($check[$measure][$group['unit']] ?? null, (string) $group[$measure]);
                }
            }

            $rows[] = [...$group, 'good' => (string) $group['good']->toScale(3), 'reject' => (string) $group['reject']->toScale(3), 'rework' => (string) $group['rework']->toScale(3)];
        }

        $totals = self::totals($batch);
        $format = static fn (array $values): array => array_map(static fn (BigDecimal $value): string => (string) $value->toScale(3), $values);

        return [
            'rows' => $rows,
            'reconciles' => $format($check['good']) == $totals['quantity'] && $format($check['reject']) == $totals['reject'] && $format($check['rework']) == $totals['rework'],
        ];
    }

    /**
     * The latest current batch of every team and kind in the account's
     * scope, with its source extraction time, so old data never looks
     * current.
     *
     * @return list<array{unit: string, kind: BatchKind, batch: ProductionBatch|null, staged: int}>
     */
    public static function freshness(User $user): array
    {
        $access = app(ProductionAccess::class);
        $result = [];

        foreach (app(OrganizationDirectory::class)->snapshot(app(AccessControl::class)->businessToday())->all() as $unit) {
            if (! $access->readScope($user)->coversUnit($unit->id)) {
                continue;
            }

            foreach ($access->kindsFor($unit->id) as $kind) {
                $latest = ProductionBatch::query()->current()->where('organization_unit_id', $unit->id)->where('kind', $kind->value)
                    ->orderByDesc('coverage_date')->orderByDesc('version')->first();
                $staged = ProductionBatch::query()->where('organization_unit_id', $unit->id)->where('kind', $kind->value)->where('status', ProductionBatch::STAGED)->count();

                $result[] = ['unit' => "{$unit->code} — {$unit->name}", 'kind' => $kind, 'batch' => $latest, 'staged' => $staged];
            }
        }

        return $result;
    }

    /**
     * Good output per work order, team, and unit over the current production
     * batches. Teams are kept apart: a work order that passes several
     * processes reports good pieces at each of them, and adding those up
     * would count the same pieces more than once.
     *
     * @param  list<string>  $workOrders
     * @return array<string, array<string, array<string, string>>> work order => team code => unit => good quantity
     */
    public static function outputByWorkOrder(array $workOrders): array
    {
        $result = [];
        $teams = ProductionBatch::query()->current()->where('kind', BatchKind::Production->value)
            ->join('organization_units', 'organization_units.id', '=', 'production_batches.organization_unit_id')
            ->pluck('organization_units.code', 'production_batches.id');

        if ($teams->isEmpty()) {
            return [];
        }

        foreach (array_chunk($workOrders, 500) as $chunk) {
            $rows = ProductionRecord::query()
                ->where('record_type', ProductionRecord::OUTPUT)
                ->whereIn('work_order', $chunk)
                ->whereIn('production_batch_id', $teams->keys()->all())
                ->toBase()
                ->get(['production_batch_id', 'work_order', 'unit', 'quantity']);

            foreach ($rows as $row) {
                $team = (string) $teams[$row->production_batch_id];
                $result[(string) $row->work_order][$team][(string) $row->unit] = self::add($result[(string) $row->work_order][$team][(string) $row->unit] ?? null, $row->quantity);
            }
        }

        return array_map(static fn (array $byTeam): array => array_map(static fn (array $units): array => array_map(static fn (BigDecimal $value): string => (string) $value->toScale(3), $units), $byTeam), $result);
    }

    /**
     * Stock rows and quantity per unit for each location category of a
     * warehouse batch.
     *
     * @return array<string, array{rows: int, quantity: array<string, string>}>
     */
    public static function stockByCategory(ProductionBatch $batch): array
    {
        $result = [];

        foreach (ProductionRecord::query()->where('production_batch_id', $batch->id)->toBase()->select(['id', 'location_category', 'unit', 'quantity'])->lazyById(1000) as $row) {
            $category = (string) $row->location_category;
            $result[$category]['rows'] = ($result[$category]['rows'] ?? 0) + 1;
            $result[$category]['sums'][(string) $row->unit] = self::add($result[$category]['sums'][(string) $row->unit] ?? null, $row->quantity);
        }

        return array_map(static fn (array $category): array => [
            'rows' => $category['rows'],
            'quantity' => array_map(static fn (BigDecimal $value): string => (string) $value->toScale(3), $category['sums']),
        ], $result);
    }

    public static function primaryType(BatchKind $kind): string
    {
        return match ($kind) {
            BatchKind::Production => ProductionRecord::OUTPUT,
            BatchKind::Packing => ProductionRecord::PACKING,
            BatchKind::Delivery => ProductionRecord::DELIVERY,
            BatchKind::Warehouse => ProductionRecord::STOCK,
            BatchKind::WorkOrders => ProductionRecord::WORK_ORDER,
        };
    }

    private static function add(?BigDecimal $sum, mixed $value): BigDecimal
    {
        $sum ??= BigDecimal::zero();

        return $value === null || $value === '' ? $sum : $sum->plus((string) $value);
    }
}
