<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Concerns\HasGovernanceLifecycle;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $kpi_template_id
 * @property int $version_number
 * @property LifecycleStatus $status
 * @property string|null $notes
 * @property string|null $signoff_reference
 * @property string|null $effective_from
 * @property string|null $effective_until
 * @property string|null $change_reason
 * @property int|null $previous_version_id
 * @property int|null $created_by_user_id
 * @property int|null $submitted_by_user_id
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $returned_by_user_id
 * @property CarbonImmutable|null $returned_at
 * @property string|null $return_reason
 * @property int|null $published_by_user_id
 * @property CarbonImmutable|null $published_at
 * @property int|null $retired_by_user_id
 * @property CarbonImmutable|null $retired_at
 * @property string|null $retire_reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read KpiTemplate $template
 * @property-read User|null $createdBy
 * @property-read User|null $submittedBy
 * @property-read User|null $publishedBy
 * @property-read User|null $returnedBy
 * @property-read User|null $retiredBy
 */
final class KpiTemplateVersion extends Model
{
    use HasGovernanceLifecycle, HasPublicUlid;

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lock_version' => 1,
    ];

    /**
     * @return BelongsTo<KpiTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(KpiTemplate::class, 'kpi_template_id');
    }

    /**
     * @return HasMany<KpiTemplateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(KpiTemplateItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...$this->lifecycleCasts(),
            'effective_from' => BusinessDate::class,
            'effective_until' => BusinessDate::class,
        ];
    }
}
