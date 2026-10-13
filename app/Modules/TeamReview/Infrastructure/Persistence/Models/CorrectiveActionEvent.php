<?php

declare(strict_types=1);

namespace App\Modules\TeamReview\Infrastructure\Persistence\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in the history of a corrective action. Append-only.
 *
 * @property int $id
 * @property int $corrective_action_id
 * @property string $event
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $note
 * @property int $actor_user_id
 * @property CarbonImmutable $occurred_at
 * @property-read User $actor
 */
final class CorrectiveActionEvent extends Model
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
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
