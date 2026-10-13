<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Infrastructure\Persistence\Models;

use App\Modules\Scoring\Domain\KpiDataStatus;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One KPI line of a calculation with its intermediate values. Immutable
 * (database trigger).
 *
 * @property int $id
 * @property int $scorecard_calculation_id
 * @property int $line_number
 * @property int|null $period_target_id
 * @property int $kpi_definition_version_id
 * @property string $kpi_code
 * @property string $kpi_name
 * @property string|null $component
 * @property string $weight
 * @property bool $is_mandatory
 * @property string|null $strategy_key
 * @property int|null $strategy_version
 * @property string|null $aggregation
 * @property KpiDataStatus $data_status
 * @property string|null $note
 * @property string|null $target_value
 * @property string|null $measured_value
 * @property string|null $achievement
 * @property string|null $kpi_score
 * @property string|null $contribution
 * @property array<string, mixed> $details
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ScorecardCalculation $calculation
 * @property-read PeriodTarget|null $target
 */
final class ScorecardCalculationLine extends Model
{
    protected $guarded = [];

    /**
     * Entries the line considered, with the decision taken for each.
     *
     * @return list<array<string, mixed>>
     */
    public function trace(): array
    {
        $trace = $this->details['trace'] ?? [];

        return is_array($trace) ? array_values($trace) : [];
    }

    /**
     * @return BelongsTo<ScorecardCalculation, $this>
     */
    public function calculation(): BelongsTo
    {
        return $this->belongsTo(ScorecardCalculation::class, 'scorecard_calculation_id');
    }

    /**
     * @return BelongsTo<PeriodTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PeriodTarget::class, 'period_target_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_status' => KpiDataStatus::class,
            'details' => 'array',
            'is_mandatory' => 'boolean',
            'line_number' => 'integer',
            'strategy_version' => 'integer',
            'weight' => 'decimal:4',
            'target_value' => 'decimal:4',
            'kpi_score' => 'decimal:4',
            'contribution' => 'decimal:4',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
