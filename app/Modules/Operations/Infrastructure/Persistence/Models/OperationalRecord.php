<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operational record of a team. Never edited or deleted; a mistake is
 * voided with a reason (database triggers).
 *
 * @property int $id
 * @property string $public_id
 * @property string $record_type
 * @property int $organization_unit_id
 * @property string $occurred_on
 * @property string $source_system
 * @property string $source_reference
 * @property int|null $kpi_definition_id
 * @property array<string, string|int|null> $details
 * @property int $recorded_by_user_id
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by_user_id
 * @property string|null $void_reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OrganizationUnit $unit
 * @property-read KpiDefinition|null $kpiDefinition
 * @property-read User $recordedBy
 * @property-read User|null $voidedBy
 */
final class OperationalRecord extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lock_version' => 1,
    ];

    /**
     * @return array<string, string|int|null>
     */
    public function values(): array
    {
        /** @var array<string, string|int|null> $values */
        $values = $this->details ?? [];

        return $values;
    }

    /**
     * @return BelongsTo<OrganizationUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    /**
     * @return BelongsTo<KpiDefinition, $this>
     */
    public function kpiDefinition(): BelongsTo
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'occurred_on' => BusinessDate::class,
            'voided_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
