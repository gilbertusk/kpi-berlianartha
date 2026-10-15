<?php

declare(strict_types=1);

namespace App\Modules\Production\Infrastructure\Persistence\Models;

use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a batch. `quantity` is the row's main quantity: good output,
 * waste, packed, shipped, on hand, or planned, by record type.
 *
 * @property int $id
 * @property int $production_batch_id
 * @property int $source_line
 * @property string $record_type
 * @property string $event_key
 * @property string|null $work_order
 * @property string|null $product_code
 * @property string|null $machine_code
 * @property string|null $customer_code
 * @property string|null $shift_code
 * @property string|null $operational_date
 * @property string|null $due_date
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ended_at
 * @property string|null $unit
 * @property string|null $quantity
 * @property string|null $reject_quantity
 * @property string|null $rework_quantity
 * @property int|null $downtime_minutes
 * @property string|null $reason_code
 * @property string|null $defect_code
 * @property string|null $location_category
 * @property array<string, string|null>|null $details
 * @property string $row_hash
 * @property-read ProductionBatch $batch
 */
final class ProductionRecord extends Model
{
    public const OUTPUT = 'output';

    public const DOWNTIME = 'downtime';

    public const WASTE = 'waste';

    public const PACKING = 'packing';

    public const DELIVERY = 'delivery';

    public const STOCK = 'stock';

    public const WORK_ORDER = 'work_order';

    protected $guarded = [];

    /**
     * @return BelongsTo<ProductionBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }

    public function detail(string $key): ?string
    {
        return $this->details[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_line' => 'integer',
            'operational_date' => BusinessDate::class,
            'due_date' => BusinessDate::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'quantity' => 'decimal:3',
            'reject_quantity' => 'decimal:3',
            'rework_quantity' => 'decimal:3',
            'downtime_minutes' => 'integer',
            'details' => 'array',
        ];
    }
}
