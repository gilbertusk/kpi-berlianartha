<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Domain\EntryKind;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use App\Modules\Target\Domain\TargetValueRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The stated value of an actual. Editable only as a draft; a verified
 * version accepts no change (database trigger).
 *
 * @property int $id
 * @property string $public_id
 * @property int $kpi_actual_id
 * @property int $version_number
 * @property ActualStatus $status
 * @property EntryKind $entry_kind
 * @property string|null $actual_value decimal string, 4 decimals
 * @property string $unit
 * @property string $source
 * @property int|null $actual_import_batch_id
 * @property string|null $note
 * @property bool $is_late
 * @property string|null $late_reason
 * @property int|null $previous_version_id
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property int|null $submitted_by_user_id
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $verified_by_user_id
 * @property CarbonImmutable|null $verified_at
 * @property int|null $rejected_by_user_id
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $reject_reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read KpiActual $actual
 * @property-read User|null $updatedBy
 * @property-read User|null $submittedBy
 * @property-read User|null $verifiedBy
 */
final class KpiActualVersion extends Model
{
    use HasPublicUlid;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    /** A value written by an approved correction of a verified actual. */
    public const SOURCE_CORRECTION = 'correction';

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lock_version' => 1,
        'is_late' => false,
    ];

    public function displayValue(): string
    {
        return $this->entry_kind === EntryKind::NotApplicable ? 'N/A' : TargetValueRules::display($this->actual_value);
    }

    /**
     * @return BelongsTo<KpiActual, $this>
     */
    public function actual(): BelongsTo
    {
        return $this->belongsTo(KpiActual::class, 'kpi_actual_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
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
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ActualStatus::class,
            'entry_kind' => EntryKind::class,
            'actual_value' => 'decimal:4',
            'is_late' => 'boolean',
            'version_number' => 'integer',
            'lock_version' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
