<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_unit_id
 * @property int|null $parent_unit_id
 * @property string $name
 * @property string $effective_from
 * @property string|null $effective_until
 * @property string|null $reason
 * @property int|null $created_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OrganizationUnit $unit
 * @property-read OrganizationUnit|null $parent
 * @property-read User|null $createdBy
 */
final class OrganizationUnitVersion extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<OrganizationUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    /**
     * @return BelongsTo<OrganizationUnit, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'parent_unit_id');
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
            'effective_from' => BusinessDate::class,
            'effective_until' => BusinessDate::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
