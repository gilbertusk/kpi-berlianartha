<?php

declare(strict_types=1);

namespace App\Modules\Quality\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionRecord;
use App\Modules\Quality\Domain\NcrSource;
use App\Modules\Quality\Domain\NcrStage;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A nonconformance report and its CAPA.
 *
 * @property int $id
 * @property string $public_id
 * @property string $number
 * @property int $qc_unit_id
 * @property int $responsible_unit_id
 * @property NcrStage $stage
 * @property int $cycle
 * @property NcrSource $source_type
 * @property string $source_reference
 * @property int|null $production_record_id
 * @property string $category_code
 * @property string|null $severity
 * @property string $description
 * @property string|null $quantity
 * @property string|null $unit
 * @property string|null $process_code
 * @property string|null $work_order
 * @property string|null $product_code
 * @property string|null $lot
 * @property string|null $machine_code
 * @property string|null $shift_code
 * @property CarbonImmutable $detected_at
 * @property int|null $repeat_of_ncr_id
 * @property int|null $kpi_definition_version_id
 * @property int $owner_user_id
 * @property int $raised_by_user_id
 * @property CarbonImmutable|null $closed_at
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OrganizationUnit $qcUnit
 * @property-read OrganizationUnit $responsibleUnit
 * @property-read User $owner
 * @property-read QualityNcr|null $repeatOf
 * @property-read KpiDefinitionVersion|null $kpiVersion
 * @property-read ProductionRecord|null $sourceRecord
 * @property-read QualityNcrStage|null $openStage
 */
final class QualityNcr extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    /**
     * @return BelongsTo<OrganizationUnit, $this>
     */
    public function qcUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'qc_unit_id');
    }

    /**
     * @return BelongsTo<OrganizationUnit, $this>
     */
    public function responsibleUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'responsible_unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<QualityNcr, $this>
     */
    public function repeatOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'repeat_of_ncr_id');
    }

    /**
     * @return HasMany<QualityNcr, $this>
     */
    public function repeats(): HasMany
    {
        return $this->hasMany(self::class, 'repeat_of_ncr_id');
    }

    /**
     * @return BelongsTo<KpiDefinitionVersion, $this>
     */
    public function kpiVersion(): BelongsTo
    {
        return $this->belongsTo(KpiDefinitionVersion::class, 'kpi_definition_version_id');
    }

    /**
     * @return BelongsTo<ProductionRecord, $this>
     */
    public function sourceRecord(): BelongsTo
    {
        return $this->belongsTo(ProductionRecord::class, 'production_record_id');
    }

    /**
     * @return HasMany<QualityNcrStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(QualityNcrStage::class)->orderBy('sequence');
    }

    /**
     * @return HasOne<QualityNcrStage, $this>
     */
    public function openStage(): HasOne
    {
        return $this->hasOne(QualityNcrStage::class)->whereNull('completed_at');
    }

    /**
     * @return HasMany<QualityNcrEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(QualityNcrEvent::class)->orderBy('sequence');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => NcrStage::class,
            'source_type' => NcrSource::class,
            'cycle' => 'integer',
            'quantity' => 'decimal:3',
            'detected_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
