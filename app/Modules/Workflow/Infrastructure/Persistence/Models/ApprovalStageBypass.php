<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signed permission to skip the review stage for the scorecards of one
 * team that has no reviewer (PRD: bypass tahap Manager per team). It names
 * the sign-off it rests on and the dates it holds for; its content never
 * changes, it can only be revoked.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_unit_id
 * @property string $stage
 * @property string $signoff_reference
 * @property string $reason
 * @property string $effective_from
 * @property string|null $effective_until
 * @property int $created_by_user_id
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property string|null $revoke_reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OrganizationUnit $unit
 * @property-read User $createdBy
 */
final class ApprovalStageBypass extends Model
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
     * Half-open: effective_from inclusive, effective_until exclusive.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInForceOn(Builder $query, string $businessDate): void
    {
        $query->whereNull('revoked_at')
            ->where('effective_from', '<=', $businessDate)
            ->where(fn (Builder $until) => $until->whereNull('effective_until')->orWhere('effective_until', '>', $businessDate));
    }

    public function statusOn(string $businessDate): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->effective_until !== null && $this->effective_until <= $businessDate => 'expired',
            $this->effective_from > $businessDate => 'scheduled',
            default => 'active',
        };
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
            'effective_from' => BusinessDate::class,
            'effective_until' => BusinessDate::class,
            'revoked_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
