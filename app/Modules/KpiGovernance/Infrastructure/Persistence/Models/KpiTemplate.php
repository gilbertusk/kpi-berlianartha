<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A scorecard template for one scope: organization unit + subject (employee
 * or team scorecard) + optional position. One scope has exactly one
 * template; its versions never overlap in time.
 *
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property int $organization_unit_id
 * @property string $subject
 * @property string|null $position_title
 * @property string $position_key
 * @property int|null $created_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OrganizationUnit $unit
 * @property-read User|null $createdBy
 */
final class KpiTemplate extends Model
{
    use HasPublicUlid;

    /** @var array<string, string> */
    public const SUBJECTS = [
        'employee' => 'Scorecard individu',
        'team' => 'Scorecard team',
    ];

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return HasMany<KpiTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(KpiTemplateVersion::class)->orderBy('version_number');
    }

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
