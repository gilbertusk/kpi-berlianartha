<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Infrastructure\Persistence\Concerns;

use App\Models\User;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared workflow columns and queries of KPI definition and template
 * versions.
 *
 * @phpstan-require-extends Model
 */
trait HasGovernanceLifecycle
{
    /**
     * In effect on the business date (half-open interval). Retired versions
     * still answer for the dates on which they were in force.
     *
     * @param  Builder<static>  $query
     */
    public function scopeEffectiveOn(Builder $query, string $businessDate): void
    {
        $query->whereIn('status', [LifecycleStatus::Published->value, LifecycleStatus::Retired->value])
            ->where('effective_from', '<=', $businessDate)
            ->where(fn (Builder $period) => $period
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', $businessDate));
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function lifecycleCasts(): array
    {
        return [
            'status' => LifecycleStatus::class,
            'submitted_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'version_number' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
