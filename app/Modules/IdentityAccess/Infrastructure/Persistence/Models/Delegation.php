<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time-boxed transfer of part of a role assignment to another account. It
 * always ends on effective_until; expiry_recorded_at only records that the
 * scheduler wrote the expiry audit, authorization never depends on it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $source_role_assignment_id
 * @property int $delegator_user_id
 * @property int $delegate_user_id
 * @property Role $role
 * @property int $organization_unit_id
 * @property string $effective_from
 * @property string $effective_until
 * @property string $reason
 * @property int|null $granted_by_user_id
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property string|null $revoke_reason
 * @property CarbonImmutable|null $expiry_recorded_at
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read RoleAssignment $sourceAssignment
 * @property-read User $delegator
 * @property-read User $delegate
 * @property-read OrganizationUnit $unit
 * @property-read User|null $grantedBy
 * @property-read User|null $revokedBy
 */
final class Delegation extends Model
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
     * @param  Builder<self>  $query
     */
    public function scopeActiveAt(Builder $query, string $businessDate, CarbonImmutable $at): void
    {
        $query->where('effective_from', '<=', $businessDate)
            ->where('effective_until', '>', $businessDate)
            ->where(fn (Builder $revocation) => $revocation
                ->whereNull('revoked_at')
                ->orWhere('revoked_at', '>', $at));
    }

    public function statusAt(string $businessDate, CarbonImmutable $at): string
    {
        return match (true) {
            $this->revoked_at !== null && $this->revoked_at->lessThanOrEqualTo($at) => 'revoked',
            $this->effective_until <= $businessDate => 'expired',
            $this->effective_from > $businessDate => 'scheduled',
            default => 'active',
        };
    }

    /**
     * @return BelongsTo<RoleAssignment, $this>
     */
    public function sourceAssignment(): BelongsTo
    {
        return $this->belongsTo(RoleAssignment::class, 'source_role_assignment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
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
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'effective_from' => BusinessDate::class,
            'effective_until' => BusinessDate::class,
            'revoked_at' => 'immutable_datetime',
            'expiry_recorded_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
