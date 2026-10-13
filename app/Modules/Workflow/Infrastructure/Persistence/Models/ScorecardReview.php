<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Persistence\Models;

use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Scoring\Infrastructure\Persistence\Models\ScorecardCalculation;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use App\Modules\Workflow\Domain\ReviewState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The approval state of one scorecard in a period under review. It points
 * at the calculation being approved; the score itself lives in the immutable
 * calculation and, when calibrated, in the calibration record of the round.
 *
 * @property int $id
 * @property string $public_id
 * @property int $assessment_period_id
 * @property int $period_assignment_id
 * @property int $scorecard_calculation_id
 * @property ReviewState $state
 * @property int $round
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AssessmentPeriod $period
 * @property-read PeriodAssignment $assignment
 * @property-read ScorecardCalculation $calculation
 * @property-read Collection<int, ScorecardReviewDecision> $decisions
 * @property-read Collection<int, ScorecardCalibration> $calibrations
 */
final class ScorecardReview extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'round' => 1,
        'lock_version' => 1,
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function pendingStage(): ?ApprovalStage
    {
        $stage = $this->state->pendingStage();

        return $stage === null ? null : ApprovalStage::from($stage);
    }

    /**
     * The calibration that applies to the score now under approval: the one
     * of the current round, made for the current calculation.
     */
    public function currentCalibration(): ?ScorecardCalibration
    {
        return $this->calibrations->first(
            fn (ScorecardCalibration $calibration): bool => $calibration->round === $this->round
                && $calibration->scorecard_calculation_id === $this->scorecard_calculation_id,
        );
    }

    /**
     * @return BelongsTo<AssessmentPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AssessmentPeriod::class, 'assessment_period_id');
    }

    /**
     * @return BelongsTo<PeriodAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(PeriodAssignment::class, 'period_assignment_id');
    }

    /**
     * @return BelongsTo<ScorecardCalculation, $this>
     */
    public function calculation(): BelongsTo
    {
        return $this->belongsTo(ScorecardCalculation::class, 'scorecard_calculation_id');
    }

    /**
     * @return HasMany<ScorecardReviewDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(ScorecardReviewDecision::class)->orderBy('id');
    }

    /**
     * @return HasMany<ScorecardCalibration, $this>
     */
    public function calibrations(): HasMany
    {
        return $this->hasMany(ScorecardCalibration::class)->orderByDesc('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => ReviewState::class,
            'round' => 'integer',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
