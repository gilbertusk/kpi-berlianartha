<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Database\Factories\KpiDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stable identity of a KPI in the dictionary. All meaning lives in
 * versions. "origin" separates PRD candidates from authored KPIs for good.
 *
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $origin
 * @property int $owner_unit_id
 * @property int|null $created_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OrganizationUnit $ownerUnit
 * @property-read User|null $createdBy
 */
final class KpiDefinition extends Model
{
    /** @use HasFactory<KpiDefinitionFactory> */
    use HasFactory, HasPublicUlid;

    public const ORIGIN_AUTHORED = 'authored';

    public const ORIGIN_PRD_CANDIDATE = 'prd_candidate';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function newFactory(): KpiDefinitionFactory
    {
        return KpiDefinitionFactory::new();
    }

    public function isCandidate(): bool
    {
        return $this->origin === self::ORIGIN_PRD_CANDIDATE;
    }

    /**
     * @return HasMany<KpiDefinitionVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(KpiDefinitionVersion::class)->orderBy('version_number');
    }

    /**
     * @return BelongsTo<OrganizationUnit, $this>
     */
    public function ownerUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'owner_unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
