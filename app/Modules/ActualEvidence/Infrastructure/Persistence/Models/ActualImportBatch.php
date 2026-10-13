<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staged actual import. Rows are validated and kept here first; nothing
 * reaches the actuals until the whole batch is committed.
 *
 * @property int $id
 * @property string $public_id
 * @property int $assessment_period_id
 * @property string $status
 * @property string $file_name
 * @property string $file_format
 * @property string $file_checksum
 * @property int $row_count
 * @property int $valid_count
 * @property int $unchanged_count
 * @property int $error_count
 * @property array<string, mixed>|null $summary
 * @property int|null $uploaded_by_user_id
 * @property int|null $committed_by_user_id
 * @property CarbonImmutable|null $committed_at
 * @property int|null $discarded_by_user_id
 * @property CarbonImmutable|null $discarded_at
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AssessmentPeriod $period
 * @property-read User|null $uploadedBy
 */
final class ActualImportBatch extends Model
{
    use HasPublicUlid;

    public const STATUS_STAGED = 'staged';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_DISCARDED = 'discarded';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_STAGED => 'Menunggu commit',
        self::STATUS_COMMITTED => 'Sudah di-commit',
        self::STATUS_DISCARDED => 'Dibatalkan',
    ];

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

    /**
     * @return BelongsTo<AssessmentPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AssessmentPeriod::class, 'assessment_period_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'row_count' => 'integer',
            'valid_count' => 'integer',
            'unchanged_count' => 'integer',
            'error_count' => 'integer',
            'lock_version' => 'integer',
            'committed_at' => 'immutable_datetime',
            'discarded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
