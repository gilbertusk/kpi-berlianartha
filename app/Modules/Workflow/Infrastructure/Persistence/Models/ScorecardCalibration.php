<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The calibration of one scorecard in one round. The calculated score and
 * rating are copied next to the calibrated ones, so the adjustment is always
 * visible; the calculation snapshot is never touched. Append-only.
 *
 * @property int $id
 * @property string $public_id
 * @property int $scorecard_review_id
 * @property int $round
 * @property int $scorecard_calculation_id
 * @property string $original_final_score
 * @property string $original_rating_key
 * @property string $original_rating_label
 * @property string $calibrated_final_score
 * @property string $calibrated_rating_key
 * @property string $calibrated_rating_label
 * @property bool $is_adjusted
 * @property string|null $reason
 * @property string|null $evidence_reference
 * @property int $calibrated_by_user_id
 * @property CarbonImmutable $calibrated_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $calibratedBy
 */
final class ScorecardCalibration extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function calibratedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calibrated_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'original_final_score' => 'decimal:4',
            'calibrated_final_score' => 'decimal:4',
            'is_adjusted' => 'boolean',
            'calibrated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
