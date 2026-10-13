<?php

declare(strict_types=1);

namespace App\Modules\Target\Infrastructure\Persistence\Models;

use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\PeriodAssignment\Domain\SubjectType;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use App\Modules\Target\Domain\TargetValueRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A target slot: one KPI definition version for one subject in one period.
 * Slots are created from the assignment snapshot when the period opens and
 * never change (database trigger); the value lives in versions.
 *
 * @property int $id
 * @property string $public_id
 * @property int $assessment_period_id
 * @property int $kpi_definition_version_id
 * @property string $kpi_code
 * @property string $kpi_name
 * @property SubjectType $subject_type
 * @property string $subject_key
 * @property int|null $employee_id
 * @property int $organization_unit_id
 * @property string $subject_code
 * @property string $subject_name
 * @property string $unit_code
 * @property string $unit_name
 * @property bool $is_required
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AssessmentPeriod $period
 * @property-read KpiDefinitionVersion $definitionVersion
 * @property-read Employee|null $employee
 */
final class PeriodTarget extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function valueRules(): TargetValueRules
    {
        $definition = $this->definitionVersion;

        return TargetValueRules::for(
            $definition->unit,
            $definition->value_type,
            $definition->decimal_places,
            $definition->min_valid_value,
            $definition->max_valid_value,
            $definition->score_parameters,
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
     * @return BelongsTo<KpiDefinitionVersion, $this>
     */
    public function definitionVersion(): BelongsTo
    {
        return $this->belongsTo(KpiDefinitionVersion::class, 'kpi_definition_version_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<PeriodTargetVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PeriodTargetVersion::class)->orderBy('version_number');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_type' => SubjectType::class,
            'is_required' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
