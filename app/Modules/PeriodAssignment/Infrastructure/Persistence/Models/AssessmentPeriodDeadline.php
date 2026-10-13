<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Infrastructure\Persistence\Models;

use App\Modules\PeriodAssignment\Domain\DeadlineStage;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Deadline of one stage: the stage closes at the end of `due_on` in the
 * period's timezone.
 *
 * @property int $id
 * @property int $assessment_period_id
 * @property DeadlineStage $stage
 * @property string $due_on
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class AssessmentPeriodDeadline extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => DeadlineStage::class,
            'due_on' => BusinessDate::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
