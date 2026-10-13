<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Scoring\Domain\ScorecardStatus;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One immutable calculation of one subject's scorecard: the complete input,
 * the policy and engine version, the result, who ran it, and why. Rows are
 * never updated or deleted (database trigger); a recalculation adds the
 * next sequence number.
 *
 * @property int $id
 * @property string $public_id
 * @property int $assessment_period_id
 * @property int $period_assignment_id
 * @property int $sequence_number
 * @property int|null $previous_calculation_id
 * @property int $engine_version
 * @property string $policy_key
 * @property int $policy_version
 * @property string $policy_signoff_reference
 * @property string $policy_hash
 * @property array<string, mixed> $policy
 * @property string $input_hash
 * @property array<string, mixed> $inputs
 * @property ScorecardStatus $status
 * @property list<string> $blockers
 * @property string $assigned_weight
 * @property string $counted_weight
 * @property string $excluded_weight
 * @property string|null $contribution_total
 * @property string|null $final_score
 * @property string|null $rating_key
 * @property string|null $rating_label
 * @property string $reason
 * @property int|null $calculated_by_user_id
 * @property CarbonImmutable $calculated_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AssessmentPeriod $period
 * @property-read PeriodAssignment $assignment
 * @property-read User|null $calculatedBy
 * @property-read Collection<int, ScorecardCalculationLine> $lines
 */
final class ScorecardCalculation extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    public function policyReference(): string
    {
        return "{$this->policy_key}@{$this->policy_version}";
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
     * @return BelongsTo<User, $this>
     */
    public function calculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by_user_id');
    }

    /**
     * @return HasMany<ScorecardCalculationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ScorecardCalculationLine::class)->orderBy('line_number');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScorecardStatus::class,
            'policy' => 'array',
            'inputs' => 'array',
            'blockers' => 'array',
            'sequence_number' => 'integer',
            'engine_version' => 'integer',
            'policy_version' => 'integer',
            'assigned_weight' => 'decimal:4',
            'counted_weight' => 'decimal:4',
            'excluded_weight' => 'decimal:4',
            'contribution_total' => 'decimal:4',
            'final_score' => 'decimal:4',
            'calculated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
