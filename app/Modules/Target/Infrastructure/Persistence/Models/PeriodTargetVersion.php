<?php

declare(strict_types=1);

namespace App\Modules\Target\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use App\Modules\Target\Domain\TargetStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One value of a target slot. Editable only as a draft; submitted and
 * approved versions are immutable (database trigger). A revision of a final
 * target is a new version that carries a reason.
 *
 * @property int $id
 * @property string $public_id
 * @property int $period_target_id
 * @property int $version_number
 * @property TargetStatus $status
 * @property string $target_value decimal string, 4 decimals
 * @property string $unit
 * @property string $source
 * @property string|null $source_note
 * @property int|null $target_import_batch_id
 * @property string|null $change_reason
 * @property bool $after_cutoff
 * @property int|null $previous_version_id
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property int|null $submitted_by_user_id
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $returned_by_user_id
 * @property CarbonImmutable|null $returned_at
 * @property string|null $return_reason
 * @property int|null $approved_by_user_id
 * @property CarbonImmutable|null $approved_at
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PeriodTarget $target
 * @property-read User|null $createdBy
 * @property-read User|null $submittedBy
 * @property-read User|null $approvedBy
 */
final class PeriodTargetVersion extends Model
{
    use HasPublicUlid;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lock_version' => 1,
        'after_cutoff' => false,
    ];

    /**
     * @return BelongsTo<PeriodTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PeriodTarget::class, 'period_target_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TargetStatus::class,
            'target_value' => 'decimal:4',
            'after_cutoff' => 'boolean',
            'version_number' => 'integer',
            'lock_version' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
