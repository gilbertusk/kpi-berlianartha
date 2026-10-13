<?php

declare(strict_types=1);

namespace App\Modules\TeamReview\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use App\Modules\TeamReview\Domain\ActionPriority;
use App\Modules\TeamReview\Domain\ActionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What is done about a team review issue: one owner (PIC), one due date,
 * a status, and the completion the owner reported. Never deleted.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_review_issue_id
 * @property int $organization_unit_id
 * @property string $action
 * @property int $owner_user_id
 * @property string $due_on
 * @property ActionPriority $priority
 * @property ActionStatus $status
 * @property string|null $waiting_reason
 * @property string|null $completion_note
 * @property string|null $evidence_reference
 * @property CarbonImmutable|null $completed_at
 * @property int|null $reviewer_user_id
 * @property CarbonImmutable|null $verified_at
 * @property int|null $closed_by_user_id
 * @property CarbonImmutable|null $closed_at
 * @property int $created_by_user_id
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read TeamReviewIssue $issue
 * @property-read OrganizationUnit $unit
 * @property-read User $owner
 * @property-read User|null $reviewer
 * @property-read Collection<int, CorrectiveActionEvent> $events
 */
final class CorrectiveAction extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lock_version' => 1,
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isOverdueOn(string $businessDate): bool
    {
        return $this->status->isOutstanding() && $this->due_on < $businessDate;
    }

    public function awaitsVerification(): bool
    {
        return $this->status === ActionStatus::InProgress && $this->completed_at !== null;
    }

    /**
     * @return BelongsTo<TeamReviewIssue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(TeamReviewIssue::class, 'team_review_issue_id');
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
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    /**
     * @return HasMany<CorrectiveActionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(CorrectiveActionEvent::class)->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => BusinessDate::class,
            'priority' => ActionPriority::class,
            'status' => ActionStatus::class,
            'completed_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
