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
 * A role granted to an account for an organization scope and an effective
 * period. Revocation keeps the row and records when access ended.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property Role $role
 * @property int|null $organization_unit_id
 * @property string $effective_from
 * @property string|null $effective_until
 * @property string $reason
 * @property int|null $granted_by_user_id
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property string|null $revoke_reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read OrganizationUnit|null $unit
 * @property-read User|null $grantedBy
 * @property-read User|null $revokedBy
 */
final class RoleAssignment extends Model
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
     * Active on the business date and not revoked at the given instant.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActiveAt(Builder $query, string $businessDate, CarbonImmutable $at): void
    {
        $query->where('effective_from', '<=', $businessDate)
            ->where(fn (Builder $period) => $period
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', $businessDate))
            ->where(fn (Builder $revocation) => $revocation
                ->whereNull('revoked_at')
                ->orWhere('revoked_at', '>', $at));
    }

    public function isActiveAt(string $businessDate, CarbonImmutable $at): bool
    {
        return $this->effective_from <= $businessDate
            && ($this->effective_until === null || $this->effective_until > $businessDate)
            && ($this->revoked_at === null || $this->revoked_at->greaterThan($at));
    }

    /**
     * @return 'active'|'scheduled'|'ended'|'revoked'
     */
    public function statusAt(string $businessDate, CarbonImmutable $at): string
    {
        return match (true) {
            $this->revoked_at !== null && $this->revoked_at->lessThanOrEqualTo($at) => 'revoked',
            $this->effective_until !== null && $this->effective_until <= $businessDate => 'ended',
            $this->effective_from > $businessDate => 'scheduled',
            default => 'active',
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
