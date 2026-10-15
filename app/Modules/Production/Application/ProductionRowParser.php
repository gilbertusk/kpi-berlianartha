<?php

declare(strict_types=1);

namespace App\Modules\Production\Application;

use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Domain\LocationCategory;
use App\Modules\Production\Domain\ShiftCalendar;
use App\Modules\Production\Domain\ShiftCalendars;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionRecord;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Validates the rows of one batch file against the import contract of its
 * kind (docs/product/PRODUCTION_SOURCES.md). Every problem of every row is
 * reported; nothing is stored unless the whole file is valid. Local times
 * are business times; shift and operational date come from the shift
 * calendar, never from the file alone.
 */
final class ProductionRowParser
{
    public const COLUMNS = [
        'production' => ['record_type', 'event_id', 'work_order', 'product_code', 'process_code', 'machine_code', 'operator_code', 'shift_code', 'started_at', 'ended_at', 'unit', 'good_qty', 'reject_qty', 'rework_qty', 'waste_qty', 'defect_code', 'reason_code'],
        'packing' => ['event_id', 'work_order', 'product_code', 'packed_at', 'shift_code', 'unit', 'packed_qty', 'repacked_qty', 'reason_code'],
        'delivery' => ['delivery_number', 'work_order', 'customer_code', 'shipped_date', 'unit', 'shipped_qty', 'delivered_date', 'pod_reference'],
        'warehouse' => ['location_code', 'location_category', 'item_code', 'lot', 'unit', 'quantity'],
        'work_orders' => ['work_order', 'order_number', 'customer_code', 'product_code', 'process_code', 'machine_code', 'planned_qty', 'unit', 'planned_start', 'planned_end', 'need_date', 'material_status', 'design_status', 'priority'],
    ];

    public const REQUIRED = [
        'production' => ['record_type', 'event_id', 'started_at'],
        'packing' => ['event_id', 'work_order', 'packed_at', 'unit', 'packed_qty'],
        'delivery' => ['delivery_number', 'work_order', 'shipped_date', 'unit', 'shipped_qty'],
        'warehouse' => ['location_code', 'location_category', 'item_code', 'unit', 'quantity'],
        'work_orders' => ['work_order', 'planned_qty', 'unit', 'need_date', 'material_status'],
    ];

    public const MATERIAL_STATUSES = ['ready', 'partial', 'not_ready'];

    public const DESIGN_STATUSES = ['approved', 'pending', 'not_required'];

    private const QUANTITY = '/^\d{1,14}(?:\.\d{1,3})?$/';

    private const CODE = '/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,59}$/';

    private const UNIT = '/^[a-z][a-z0-9_]{0,19}$/';

    private const MAX_DOWNTIME_MINUTES = 1440;

    private int $missingReasons = 0;

    public function __construct(
        private readonly BatchKind $kind,
        private readonly string $coverageDate,
        private readonly ShiftCalendars $calendars,
        private readonly DateTimeZone $timezone,
        private readonly DateTimeImmutable $now,
    ) {}

    /**
     * @param  list<array{line: int, cells: array<string, string>}>  $rows
     * @return array{records: list<array<string, mixed>>, errors: list<string>, missing_reason: int}
     */
    public function parse(array $rows): array
    {
        $records = [];
        $errors = [];
        $seen = [];
        $this->missingReasons = 0;

        foreach ($rows as $row) {
            $line = $row['line'];
            $cells = array_map(static fn (string $value): string => trim($value), $row['cells']);
            $problems = [];

            $record = match ($this->kind) {
                BatchKind::Production => $this->production($cells, $problems),
                BatchKind::Packing => $this->packing($cells, $problems),
                BatchKind::Delivery => $this->delivery($cells, $problems),
                BatchKind::Warehouse => $this->warehouse($cells, $problems),
                BatchKind::WorkOrders => $this->workOrder($cells, $problems),
            };

            if ($record !== null) {
                $key = "{$record['record_type']}|{$record['event_key']}";

                if (isset($seen[$key])) {
                    $problems[] = "duplikat dari baris {$seen[$key]}";
                }

                $seen[$key] = $line;
            }

            if ($problems !== [] || $record === null) {
                $errors[] = "Baris {$line}: ".implode('; ', $problems).'.';

                continue;
            }

            $record['source_line'] = $line;
            $record['row_hash'] = hash('sha256', json_encode($cells, JSON_THROW_ON_ERROR));
            $records[] = $record;
        }

        if ($errors === [] && $records === []) {
            $errors[] = 'File tidak berisi baris data.';
        }

        return ['records' => $records, 'errors' => $errors, 'missing_reason' => $this->missingReasons];
    }

    /**
     * @param  array<string, string>  $cells
     * @param  list<string>  $problems
     * @return array<string, mixed>|null
     */
    private function production(array $cells, array &$problems): ?array
    {
        $type = mb_strtolower($cells['record_type'] ?? '');

        if (! in_array($type, [ProductionRecord::OUTPUT, ProductionRecord::DOWNTIME, ProductionRecord::WASTE], true)) {
            $problems[] = 'record_type harus output, downtime, atau waste';

            return null;
        }

        $event = $this->code($cells, 'event_id', $problems, required: true);
        $workOrder = $this->code($cells, 'work_order', $problems, required: $type === ProductionRecord::OUTPUT);
        $machine = $this->code($cells, 'machine_code', $problems, required: $type === ProductionRecord::DOWNTIME);
        [$started, $shift, $date] = $this->shiftMoment($cells, 'started_at', $problems);
        $ended = null;
        $minutes = null;
        $unit = null;
        $quantity = null;
        $reject = null;
        $rework = null;

        if ($type === ProductionRecord::OUTPUT) {
            $unit = $this->unit($cells, $problems);
            $quantity = $this->quantity($cells, 'good_qty', $problems, required: false) ?? '0';
            $reject = $this->quantity($cells, 'reject_qty', $problems, required: false) ?? '0';
            $rework = $this->quantity($cells, 'rework_qty', $problems, required: false) ?? '0';

            if (self::isZero($quantity) && self::isZero($reject) && self::isZero($rework)) {
                $problems[] = 'output membutuhkan good_qty, reject_qty, atau rework_qty lebih dari 0';
            }

            if ((! self::isZero($reject) || ! self::isZero($rework)) && ($cells['defect_code'] ?? '') === '' && ($cells['reason_code'] ?? '') === '') {
                $this->missingReasons++;
            }
        }

        if ($type === ProductionRecord::DOWNTIME) {
            $ended = $this->localMoment($cells['ended_at'] ?? '', 'ended_at', $problems, required: true);

            if ($started !== null && $ended !== null) {
                $minutes = intdiv($ended->getTimestamp() - $started->getTimestamp(), 60);

                if ($minutes <= 0) {
                    $problems[] = 'ended_at harus setelah started_at';
                } elseif ($minutes > self::MAX_DOWNTIME_MINUTES) {
                    $problems[] = 'downtime lebih dari 24 jam dalam satu baris tidak mungkin; pecah per tanggal operasional';
                }
            }

            $this->countMissingReason($cells);
        }

        if ($type === ProductionRecord::WASTE) {
            $unit = $this->unit($cells, $problems);
            $quantity = $this->quantity($cells, 'waste_qty', $problems, required: true, positive: true);
            $this->countMissingReason($cells);
        }

        return [
            'record_type' => $type,
            'event_key' => (string) $event,
            'work_order' => $workOrder,
            'product_code' => $this->code($cells, 'product_code', $problems),
            'machine_code' => $machine,
            'shift_code' => $shift,
            'operational_date' => $date,
            'started_at' => $started,
            'ended_at' => $ended,
            'unit' => $unit,
            'quantity' => $quantity,
            'reject_quantity' => $reject,
            'rework_quantity' => $rework,
            'downtime_minutes' => $minutes,
            'reason_code' => $this->code($cells, 'reason_code', $problems),
            'defect_code' => $this->code($cells, 'defect_code', $problems),
            'details' => $this->details($cells, ['process_code', 'operator_code'], $problems),
        ];
    }

    /**
     * @param  array<string, string>  $cells
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function packing(array $cells, array &$problems): array
    {
        [$packed, $shift, $date] = $this->shiftMoment($cells, 'packed_at', $problems);
        $repacked = $this->quantity($cells, 'repacked_qty', $problems, required: false);

        if ($repacked !== null && ! self::isZero($repacked)) {
            $this->countMissingReason($cells);
        }

        return [
            'record_type' => ProductionRecord::PACKING,
            'event_key' => (string) $this->code($cells, 'event_id', $problems, required: true),
            'work_order' => $this->code($cells, 'work_order', $problems, required: true),
            'product_code' => $this->code($cells, 'product_code', $problems),
            'shift_code' => $shift,
            'operational_date' => $date,
            'started_at' => $packed,
            'unit' => $this->unit($cells, $problems),
            'quantity' => $this->quantity($cells, 'packed_qty', $problems, required: true, positive: true),
            'rework_quantity' => $repacked,
            'reason_code' => $this->code($cells, 'reason_code', $problems),
        ];
    }

    /**
     * @param  array<string, string>  $cells
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function delivery(array $cells, array &$problems): array
    {
        $number = $this->code($cells, 'delivery_number', $problems, required: true);
        $workOrder = $this->code($cells, 'work_order', $problems, required: true);
        $shipped = $this->date($cells, 'shipped_date', $problems, required: true);
        $delivered = $this->date($cells, 'delivered_date', $problems, required: false);
        $pod = $this->code($cells, 'pod_reference', $problems);

        if ($shipped !== null && $shipped !== $this->coverageDate) {
            $problems[] = "shipped_date {$shipped} bukan tanggal kirim batch ini ({$this->coverageDate})";
        }

        if ($delivered !== null && $shipped !== null && $delivered < $shipped) {
            $problems[] = 'delivered_date sebelum shipped_date';
        }

        if ($delivered !== null && $delivered > $this->today()) {
            $problems[] = 'delivered_date di masa depan';
        }

        if ($pod !== null && $delivered === null) {
            $problems[] = 'pod_reference membutuhkan delivered_date';
        }

        return [
            'record_type' => ProductionRecord::DELIVERY,
            'event_key' => "{$number}|{$workOrder}",
            'work_order' => $workOrder,
            'customer_code' => $this->code($cells, 'customer_code', $problems),
            'operational_date' => $shipped,
            'due_date' => $delivered,
            'unit' => $this->unit($cells, $problems),
            'quantity' => $this->quantity($cells, 'shipped_qty', $problems, required: true, positive: true),
            'details' => ['delivery_number' => $number, 'pod_reference' => $pod],
        ];
    }

    /**
     * @param  array<string, string>  $cells
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function warehouse(array $cells, array &$problems): array
    {
        $category = LocationCategory::tryFrom(mb_strtolower($cells['location_category'] ?? ''));

        if ($category === null) {
            $problems[] = 'location_category harus salah satu dari: '.implode(', ', array_map(static fn (LocationCategory $case): string => $case->value, LocationCategory::cases()));
        }

        $location = $this->code($cells, 'location_code', $problems, required: true);
        $item = $this->code($cells, 'item_code', $problems, required: true);
        $lot = $this->code($cells, 'lot', $problems);
        $unit = $this->unit($cells, $problems);

        return [
            'record_type' => ProductionRecord::STOCK,
            'event_key' => "{$location}|{$item}|{$lot}|{$unit}",
            'product_code' => $item,
            'operational_date' => $this->coverageDate,
            'unit' => $unit,
            'quantity' => $this->quantity($cells, 'quantity', $problems, required: true),
            'location_category' => $category?->value,
            'details' => ['location_code' => $location, 'lot' => $lot],
        ];
    }

    /**
     * @param  array<string, string>  $cells
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function workOrder(array $cells, array &$problems): array
    {
        $start = $this->date($cells, 'planned_start', $problems, required: false);
        $end = $this->date($cells, 'planned_end', $problems, required: false);
        $material = mb_strtolower($cells['material_status'] ?? '');
        $design = ($cells['design_status'] ?? '') === '' ? null : mb_strtolower($cells['design_status']);
        $priority = ($cells['priority'] ?? '') === '' ? null : mb_strtolower($cells['priority']);

        if ($start !== null && $end !== null && $end < $start) {
            $problems[] = 'planned_end sebelum planned_start';
        }

        if (! in_array($material, self::MATERIAL_STATUSES, true)) {
            $problems[] = 'material_status harus ready, partial, atau not_ready';
        }

        if ($design !== null && ! in_array($design, self::DESIGN_STATUSES, true)) {
            $problems[] = 'design_status harus approved, pending, atau not_required';
        }

        if ($priority !== null && ! in_array($priority, ['normal', 'emergency'], true)) {
            $problems[] = 'priority harus normal atau emergency';
        }

        $workOrder = $this->code($cells, 'work_order', $problems, required: true);

        return [
            'record_type' => ProductionRecord::WORK_ORDER,
            'event_key' => (string) $workOrder,
            'work_order' => $workOrder,
            'customer_code' => $this->code($cells, 'customer_code', $problems),
            'product_code' => $this->code($cells, 'product_code', $problems),
            'machine_code' => $this->code($cells, 'machine_code', $problems),
            'operational_date' => $this->coverageDate,
            'due_date' => $this->date($cells, 'need_date', $problems, required: true),
            'unit' => $this->unit($cells, $problems),
            'quantity' => $this->quantity($cells, 'planned_qty', $problems, required: true, positive: true),
            'details' => [
                ...$this->details($cells, ['order_number', 'process_code'], $problems),
                'planned_start' => $start,
                'planned_end' => $end,
                'material_status' => $material,
                'design_status' => $design,
                'priority' => $priority,
            ],
        ];
    }

    /**
     * A local moment, its shift, and its operational date, which must be the
     * date of this batch: a late row belongs in the batch of its own date.
     *
     * @param  array<string, string>  $cells
     * @param  list<string>  $problems
     * @return array{0: DateTimeImmutable|null, 1: string|null, 2: string|null}
     */
    private function shiftMoment(array $cells, string $column, array &$problems): array
    {
        $moment = $this->localMoment($cells[$column] ?? '', $column, $problems, required: true);

        if ($moment === null) {
            return [null, null, null];
        }

        $local = $moment->setTimezone($this->timezone);

        try {
            $match = $this->calendars->resolve($local->format('Y-m-d'), (int) $local->format('G') * 60 + (int) $local->format('i'));
        } catch (InvalidArgumentException $exception) {
            $problems[] = "{$column}: ".$exception->getMessage();

            return [$moment, null, null];
        }

        if ($match === null) {
            $problems[] = "{$column} {$local->format('Y-m-d H:i')} tidak termasuk shift mana pun pada kalender shift";

            return [$moment, null, null];
        }

        $declared = ($cells['shift_code'] ?? '') === '' ? null : strtoupper($cells['shift_code']);

        if ($declared !== null && $declared !== $match->shiftCode) {
            $problems[] = "shift_code {$declared} tidak sesuai kalender; {$local->format('Y-m-d H:i')} termasuk shift {$match->shiftCode}";
        }

        if ($match->operationalDate !== $this->coverageDate) {
            $problems[] = "termasuk tanggal operasional {$match->operationalDate} (shift {$match->shiftCode}); impor pada batch tanggal tersebut";
        }

        return [$moment, $match->shiftCode, $match->operationalDate];
    }

    /**
     * @param  list<string>  $problems
     */
    private function localMoment(string $value, string $column, array &$problems, bool $required): ?DateTimeImmutable
    {
        if ($value === '') {
            if ($required) {
                $problems[] = "{$column} wajib diisi";
            }

            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $this->timezone);

        if ($parsed === false || $parsed->format('Y-m-d H:i') !== $value) {
            $problems[] = "{$column} harus YYYY-MM-DD HH:MM (waktu {$this->timezone->getName()})";

            return null;
        }

        if ($parsed > $this->now) {
            $problems[] = "{$column} di masa depan";
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @param  array<string, string>  $cells
     * @param  list<string>  $problems
     */
    private function date(array $cells, string $column, array &$problems, bool $required): ?string
    {
        $value = $cells[$column] ?? '';

        if ($value === '') {
            if ($required) {
                $problems[] = "{$column} wajib diisi";
            }

            return null;
        }

        if (! ShiftCalendar::isDate($value)) {
            $problems[] = "{$column} harus YYYY-MM-DD";

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  list<string>  $problems
     */
    private function quantity(array $cells, string $column, array &$problems, bool $required, bool $positive = false): ?string
    {
        $value = $cells[$column] ?? '';

        if ($value === '') {
            if ($required) {
                $problems[] = "{$column} wajib diisi";
            }

            return null;
        }

        if (str_starts_with($value, '-')) {
            $problems[] = "{$column} tidak boleh negatif";

            return null;
        }

        if (preg_match(self::QUANTITY, $value) !== 1) {
            $problems[] = "{$column} harus angka dengan titik desimal (maks. 3 desimal)";

            return null;
        }

        if ($positive && self::isZero($value)) {
            $problems[] = "{$column} harus lebih dari 0";
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  list<string>  $problems
     */
    private function unit(array $cells, array &$problems): ?string
    {
        $unit = mb_strtolower($cells['unit'] ?? '');

        if (preg_match(self::UNIT, $unit) !== 1) {
            $problems[] = 'unit wajib berupa kode satuan (mis. pcs, m, kg)';

            return null;
        }

        return $unit;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  list<string>  $problems
     */
    private function code(array $cells, string $column, array &$problems, bool $required = false): ?string
    {
        $value = $cells[$column] ?? '';

        if ($value === '') {
            if ($required) {
                $problems[] = "{$column} wajib diisi";
            }

            return null;
        }

        if (preg_match(self::CODE, $value) !== 1) {
            $problems[] = "{$column} harus berupa kode tanpa spasi";

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  list<string>  $columns
     * @param  list<string>  $problems
     * @return array<string, string|null>
     */
    private function details(array $cells, array $columns, array &$problems): array
    {
        $details = [];

        foreach ($columns as $column) {
            $details[$column] = $this->code($cells, $column, $problems);
        }

        return $details;
    }

    /**
     * @param  array<string, string>  $cells
     */
    private function countMissingReason(array $cells): void
    {
        if (($cells['reason_code'] ?? '') === '') {
            $this->missingReasons++;
        }
    }

    private function today(): string
    {
        return $this->now->setTimezone($this->timezone)->format('Y-m-d');
    }

    private static function isZero(string $quantity): bool
    {
        return preg_match('/^0+(?:\.0+)?$/', $quantity) === 1;
    }
}
