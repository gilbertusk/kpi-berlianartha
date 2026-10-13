<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Infrastructure\Persistence\Models;

use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One observation of a target: the target slot (period, KPI version,
 * subject) plus the dates it covers and, when it came from another system,
 * the source record it reconciles to. The value lives in versions.
 *
 * @property int $id
 * @property string $public_id
 * @property int $period_target_id
 * @property string $window_start
 * @property string $window_end
 * @property string|null $source_system
 * @property string|null $source_reference
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PeriodTarget $target
 * @property-read Collection<int, KpiActualVersion> $versions
 * @property-read Collection<int, EvidenceFile> $evidence
 */
final class KpiActual extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function windowLabel(): string
    {
        return $this->window_start === $this->window_end ? $this->window_start : "{$this->window_start} — {$this->window_end}";
    }

    /**
     * @return BelongsTo<PeriodTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PeriodTarget::class, 'period_target_id');
    }

    /**
     * @return HasMany<KpiActualVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(KpiActualVersion::class)->orderBy('version_number');
    }

    /**
     * Evidence that has not been removed.
     *
     * @return HasMany<EvidenceFile, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(EvidenceFile::class)->whereNull('removed_at')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_start' => BusinessDate::class,
            'window_end' => BusinessDate::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
