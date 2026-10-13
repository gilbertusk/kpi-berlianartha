<?php

declare(strict_types=1);

namespace App\Modules\TeamReview\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use App\Modules\TeamReview\Domain\IssueContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A problem a team recorded in its daily review: what happened, the cause,
 * and the KPIs it affects.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_unit_id
 * @property string $review_date
 * @property string $problem
 * @property string|null $cause_code
 * @property string|null $cause_note
 * @property IssueContext|null $context_type
 * @property string|null $context_reference
 * @property int $reported_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OrganizationUnit $unit
 * @property-read User $reportedBy
 * @property-read Collection<int, KpiDefinition> $kpis
 * @property-read Collection<int, CorrectiveAction> $actions
 */
final class TeamReviewIssue extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
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
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    /**
     * @return BelongsToMany<KpiDefinition, $this>
     */
    public function kpis(): BelongsToMany
    {
        return $this->belongsToMany(KpiDefinition::class, 'team_review_issue_kpis')->withTimestamps()->orderBy('code');
    }

    /**
     * @return HasMany<CorrectiveAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(CorrectiveAction::class)->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'review_date' => BusinessDate::class,
            'context_type' => IssueContext::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
