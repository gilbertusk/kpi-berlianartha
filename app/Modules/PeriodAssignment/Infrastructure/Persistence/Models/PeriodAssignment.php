<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Infrastructure\Persistence\Models;

use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateVersion;
use App\Modules\Organization\Infrastructure\Persistence\Models\Employee;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\SubjectType;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Snapshot of one scorecard subject in a period: who (or which unit) is
 * assessed, in which team and position, under which template version. Names
 * and the hierarchy path are copied so later organization changes never
 * alter the period. Rows are immutable (database trigger).
 *
 * @property int $id
 * @property string $public_id
 * @property int $assessment_period_id
 * @property SubjectType $subject_type
 * @property string $subject_key
 * @property int|null $employee_id
 * @property int $organization_unit_id
 * @property int|null $source_assignment_id
 * @property string $subject_code
 * @property string $subject_name
 * @property string|null $position_title
 * @property string $unit_code
 * @property string $unit_name
 * @property string $unit_type
 * @property list<array{id: int, code: string, name: string, type: string}> $unit_path
 * @property int|null $kpi_template_version_id
 * @property string|null $template_code
 * @property int|null $template_version_number
 * @property Coverage $coverage
 * @property array<string, string>|null $approver_gaps stage => gap reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AssessmentPeriod $period
 * @property-read Employee|null $employee
 * @property-read KpiTemplateVersion|null $templateVersion
 * @property-read Collection<int, PeriodAssignmentApprover> $approvers
 */
final class PeriodAssignment extends Model
{
    use HasPublicUlid;

    protected $guarded = [];

    public function pathLabel(): string
    {
        $names = [];

        foreach ($this->unit_path as $node) {
            if ($node['type'] !== 'company') {
                $names[] = $node['name'];
            }
        }

        return $names === [] ? $this->unit_name : implode(' › ', $names);
    }

    /**
     * @return BelongsTo<AssessmentPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AssessmentPeriod::class, 'assessment_period_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<KpiTemplateVersion, $this>
     */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(KpiTemplateVersion::class, 'kpi_template_version_id');
    }

    /**
     * @return HasMany<PeriodAssignmentApprover, $this>
     */
    public function approvers(): HasMany
    {
        return $this->hasMany(PeriodAssignmentApprover::class)->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_type' => SubjectType::class,
            'coverage' => Coverage::class,
            'unit_path' => 'array',
            'approver_gaps' => 'array',
            'template_version_number' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
