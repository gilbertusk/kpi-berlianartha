<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use App\Modules\Workflow\Domain\DecisionOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a scorecard's approval history: who decided what, at which
 * stage and round, under which role, for whom (delegation), and about which
 * calculation. Append-only (database trigger).
 *
 * @property int $id
 * @property string $public_id
 * @property int $scorecard_review_id
 * @property int $round
 * @property string $stage
 * @property DecisionOutcome $outcome
 * @property int $actor_user_id
 * @property string $actor_role
 * @property int|null $acting_for_user_id
 * @property string|null $grant_source
 * @property string|null $grant_reference
 * @property int|null $approval_stage_bypass_id
 * @property int $scorecard_calculation_id
 * @property string|null $comment
 * @property CarbonImmutable $decided_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $actor
 * @property-read User|null $actingFor
 * @property-read ApprovalStageBypass|null $bypass
 */
final class ScorecardReviewDecision extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actingFor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_for_user_id');
    }

    /**
     * @return BelongsTo<ApprovalStageBypass, $this>
     */
    public function bypass(): BelongsTo
    {
        return $this->belongsTo(ApprovalStageBypass::class, 'approval_stage_bypass_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => DecisionOutcome::class,
            'round' => 'integer',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
