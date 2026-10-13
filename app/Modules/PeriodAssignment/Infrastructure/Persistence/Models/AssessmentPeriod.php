<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\PeriodAssignment\Domain\DeadlineStage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Domain\PeriodType;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An assessment period for one organization scope. `starts_on` and `ends_on`
 * are inclusive business dates in `timezone`; the period keeps its own
 * timezone so its boundaries never move when the application setting does.
 *
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property PeriodType $period_type
 * @property string $starts_on
 * @property string $ends_on
 * @property string $timezone
 * @property int $organization_unit_id
 * @property string $assignment_reference_date
 * @property PeriodStatus $status
 * @property string|null $notes
 * @property int|null $created_by_user_id
 * @property int|null $opened_by_user_id
 * @property CarbonImmutable|null $opened_at
 * @property int|null $progress_started_by_user_id
 * @property CarbonImmutable|null $progress_started_at
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OrganizationUnit $scopeUnit
 * @property-read Collection<int, AssessmentPeriodDeadline> $deadlines
 * @property-read User|null $openedBy
 */
final class AssessmentPeriod extends Model
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

    public function deadlineFor(DeadlineStage $stage): ?string
    {
        return $this->deadlines->firstWhere('stage', $stage)?->due_on;
    }

    /**
     * @return array<string, string> stage value => YYYY-MM-DD, in stage order
     */
    public function deadlineMap(): array
    {
        $map = [];

        foreach (DeadlineStage::cases() as $stage) {
            $dueOn = $this->deadlineFor($stage);

            if ($dueOn !== null) {
                $map[$stage->value] = $dueOn;
            }
        }

        return $map;
    }

    /**
     * @return BelongsTo<OrganizationUnit, $this>
     */
    public function scopeUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    /**
     * @return HasMany<AssessmentPeriodDeadline, $this>
     */
    public function deadlines(): HasMany
    {
        return $this->hasMany(AssessmentPeriodDeadline::class);
    }

    /**
     * @return HasMany<PeriodAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(PeriodAssignment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_type' => PeriodType::class,
            'status' => PeriodStatus::class,
            'starts_on' => BusinessDate::class,
            'ends_on' => BusinessDate::class,
            'assignment_reference_date' => BusinessDate::class,
            'opened_at' => 'immutable_datetime',
            'progress_started_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
