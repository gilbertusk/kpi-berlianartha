<?php

declare(strict_types=1);

namespace App\Modules\Quality\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Quality\Domain\NcrStage;
use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pass of an NCR through a stage: PIC and due date when entered;
 * content, evidence, and outcome when completed. A completed stage never
 * changes.
 *
 * @property int $id
 * @property int $quality_ncr_id
 * @property int $sequence
 * @property int $cycle
 * @property NcrStage $stage
 * @property int $pic_user_id
 * @property string $due_date
 * @property CarbonImmutable $entered_at
 * @property string|null $content
 * @property string|null $root_cause_method
 * @property string|null $preventive_action
 * @property string|null $effectiveness_result
 * @property string|null $evidence_reference
 * @property bool $evidence_restricted
 * @property string|null $outcome completed|returned
 * @property int|null $completed_by_user_id
 * @property CarbonImmutable|null $completed_at
 * @property-read User $pic
 * @property-read User|null $completedBy
 */
final class QualityNcrStage extends Model
{
    public const COMPLETED = 'completed';

    public const RETURNED = 'returned';

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'cycle' => 'integer',
            'stage' => NcrStage::class,
            'due_date' => BusinessDate::class,
            'entered_at' => 'immutable_datetime',
            'evidence_restricted' => 'boolean',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
