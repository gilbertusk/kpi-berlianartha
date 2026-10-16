<?php

declare(strict_types=1);

namespace App\Modules\Quality\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Quality\Domain\NcrStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in the history of an NCR. Append-only.
 *
 * @property int $id
 * @property int $quality_ncr_id
 * @property int $sequence
 * @property string $action raised|advanced|returned|closed|reopened|corrected
 * @property NcrStage|null $from_stage
 * @property NcrStage|null $to_stage
 * @property array<string, mixed>|null $changes
 * @property string|null $reason
 * @property int $actor_user_id
 * @property CarbonImmutable $occurred_at
 * @property-read User $actor
 */
final class QualityNcrEvent extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'from_stage' => NcrStage::class,
            'to_stage' => NcrStage::class,
            'changes' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
