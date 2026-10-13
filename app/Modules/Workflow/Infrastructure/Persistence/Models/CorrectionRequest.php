<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\ActualEvidence\Domain\EntryKind;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use App\Modules\Workflow\Domain\CorrectionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to replace the verified value of one actual after its period
 * left the entry stage. The request and its decision are kept; an approved
 * request points at the actual version it created.
 *
 * @property int $id
 * @property string $public_id
 * @property int $assessment_period_id
 * @property int $kpi_actual_id
 * @property int $current_version_id
 * @property EntryKind $proposed_entry_kind
 * @property string|null $proposed_value
 * @property string|null $proposed_note
 * @property string $reason
 * @property string|null $evidence_reference
 * @property CorrectionStatus $status
 * @property int $requested_by_user_id
 * @property CarbonImmutable $requested_at
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_comment
 * @property int|null $new_version_id
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AssessmentPeriod $period
 * @property-read KpiActual $actual
 * @property-read KpiActualVersion $currentVersion
 * @property-read KpiActualVersion|null $newVersion
 * @property-read User $requestedBy
 * @property-read User|null $decidedBy
 */
final class CorrectionRequest extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lock_version' => 1,
    ];

    public function proposedDisplay(): string
    {
        return $this->proposed_entry_kind === EntryKind::NotApplicable
            ? 'N/A'
            : rtrim(rtrim((string) $this->proposed_value, '0'), '.');
    }

    /**
     * @return BelongsTo<AssessmentPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AssessmentPeriod::class, 'assessment_period_id');
    }

    /**
     * @return BelongsTo<KpiActual, $this>
     */
    public function actual(): BelongsTo
    {
        return $this->belongsTo(KpiActual::class, 'kpi_actual_id');
    }

    /**
     * @return BelongsTo<KpiActualVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(KpiActualVersion::class, 'current_version_id');
    }

    /**
     * @return BelongsTo<KpiActualVersion, $this>
     */
    public function newVersion(): BelongsTo
    {
        return $this->belongsTo(KpiActualVersion::class, 'new_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proposed_entry_kind' => EntryKind::class,
            'proposed_value' => 'decimal:4',
            'status' => CorrectionStatus::class,
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
