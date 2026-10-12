<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Persistence\Models;

use App\Modules\Organization\Domain\UnitType;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stable identity of a company, department, or team. Name and parent are
 * effective-dated in {@see OrganizationUnitVersion}.
 *
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property UnitType $unit_type
 * @property string $effective_from
 * @property string|null $effective_until
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class OrganizationUnit extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    /**
     * New rows start at version 1 so optimistic locking works on the
     * returned model without a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lock_version' => 1,
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return HasMany<OrganizationUnitVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(OrganizationUnitVersion::class)->orderBy('effective_from');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_type' => UnitType::class,
            'effective_from' => BusinessDate::class,
            'effective_until' => BusinessDate::class,
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
