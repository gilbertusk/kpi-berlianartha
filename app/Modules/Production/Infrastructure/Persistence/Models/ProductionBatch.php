<?php

declare(strict_types=1);

namespace App\Modules\Production\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a source system reported for one team, kind, and date, as one file.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_unit_id
 * @property BatchKind $kind
 * @property string $source_system
 * @property string $coverage_date
 * @property int $version
 * @property string $content_hash
 * @property string $original_name
 * @property string $status staged|committed|rejected
 * @property int $row_count
 * @property int $error_count
 * @property list<string>|null $errors
 * @property int $missing_reason_count
 * @property string|null $calendar_version
 * @property CarbonImmutable $source_extracted_at
 * @property string|null $control_quantity_total
 * @property string|null $control_reject_total
 * @property string|null $control_rework_total
 * @property int $staged_by_user_id
 * @property int|null $committed_by_user_id
 * @property CarbonImmutable|null $committed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OrganizationUnit $unit
 * @property-read User $stagedBy
 * @property-read User|null $committedBy
 */
final class ProductionBatch extends Model
{
    use HasPublicUlid;

    public const STAGED = 'staged';

    public const COMMITTED = 'committed';

    public const REJECTED = 'rejected';

    protected $guarded = [];

    /**
     * @return BelongsTo<OrganizationUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function stagedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staged_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function committedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by_user_id');
    }

    /**
     * @return HasMany<ProductionRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(ProductionRecord::class);
    }

    /**
     * Committed batches that no later committed version of the same team,
     * kind, source, and date replaces.
     *
     * @param  Builder<ProductionBatch>  $query
     * @return Builder<ProductionBatch>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', self::COMMITTED)->whereNotExists(function ($newer): void {
            $newer->selectRaw('1')
                ->from('production_batches as newer')
                ->whereColumn('newer.organization_unit_id', 'production_batches.organization_unit_id')
                ->whereColumn('newer.kind', 'production_batches.kind')
                ->whereColumn('newer.source_system', 'production_batches.source_system')
                ->whereColumn('newer.coverage_date', 'production_batches.coverage_date')
                ->whereColumn('newer.version', '>', 'production_batches.version')
                ->where('newer.status', self::COMMITTED);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BatchKind::class,
            'coverage_date' => BusinessDate::class,
            'errors' => 'array',
            'version' => 'integer',
            'row_count' => 'integer',
            'error_count' => 'integer',
            'missing_reason_count' => 'integer',
            'source_extracted_at' => 'immutable_datetime',
            'control_quantity_total' => 'decimal:3',
            'control_reject_total' => 'decimal:3',
            'control_rework_total' => 'decimal:3',
            'committed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
