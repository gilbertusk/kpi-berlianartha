<?php

declare(strict_types=1);

namespace App\Modules\Target\Application;

use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateItem;
use App\Modules\PeriodAssignment\Application\PeriodLifecycleParticipant;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\PeriodAssignment\Domain\SubjectType;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Target\Domain\TargetStatus;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Derives the targets a period needs from its assignment snapshot, in the
 * transaction that opens the period.
 *
 * Each KPI of a covered subject's template (when its position rule applies)
 * yields one slot at the level the KPI is assessed on (definition
 * `evaluation_subject`): the employee, or the team, department, or company
 * on the employee's snapshot hierarchy. A team-level KPI on the scorecards
 * of ten members is therefore one target, not ten.
 */
final class TargetSlotBuilder implements PeriodLifecycleParticipant
{
    public function periodOpened(AssessmentPeriod $period, Actor $actor): void
    {
        /** @var array<int, Collection<int, KpiTemplateItem>> $itemsByTemplate */
        $itemsByTemplate = [];
        /** @var array<string, array<string, mixed>> $slots */
        $slots = [];

        $assignments = PeriodAssignment::query()
            ->where('assessment_period_id', $period->id)
            ->where('coverage', Coverage::Covered->value)
            ->whereNotNull('kpi_template_version_id')
            ->orderBy('id')
            ->get();

        foreach ($assignments as $assignment) {
            $templateVersionId = (int) $assignment->kpi_template_version_id;
            $itemsByTemplate[$templateVersionId] ??= KpiTemplateItem::query()
                ->where('kpi_template_version_id', $templateVersionId)
                ->with('definitionVersion.definition')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            foreach ($itemsByTemplate[$templateVersionId] as $item) {
                $rule = $item->applicabilityRule();

                if ($rule !== null && ! $rule->appliesTo($assignment->position_title)) {
                    continue;
                }

                $slot = self::slotFor($assignment, $item->definitionVersion);
                $key = "{$item->kpi_definition_version_id}|{$slot['subject_key']}";

                if (isset($slots[$key])) {
                    $slots[$key]['is_required'] = $slots[$key]['is_required'] || $item->is_mandatory;

                    continue;
                }

                $slots[$key] = [
                    ...$slot,
                    'assessment_period_id' => $period->id,
                    'kpi_definition_version_id' => $item->kpi_definition_version_id,
                    'kpi_code' => $item->definitionVersion->definition->code,
                    'kpi_name' => $item->definitionVersion->name,
                    'is_required' => $item->is_mandatory,
                ];
            }
        }

        foreach ($slots as $slot) {
            PeriodTarget::query()->create($slot);
        }
    }

    public function blockersFor(AssessmentPeriod $period, PeriodStatus $next): array
    {
        if ($next !== PeriodStatus::InProgress) {
            return [];
        }

        $missing = PeriodTarget::query()
            ->where('assessment_period_id', $period->id)
            ->where('is_required', true)
            ->whereDoesntHave('versions', fn (Builder $versions) => $versions->where('status', TargetStatus::Approved->value))
            ->count();

        return $missing === 0 ? [] : ["{$missing} target wajib belum final."];
    }

    /**
     * @return array{subject_type: SubjectType, subject_key: string, employee_id: int|null, organization_unit_id: int, subject_code: string, subject_name: string, unit_code: string, unit_name: string}
     */
    private static function slotFor(PeriodAssignment $assignment, KpiDefinitionVersion $definition): array
    {
        if ($definition->evaluation_subject === 'employee' && $assignment->subject_type === SubjectType::Employee && $assignment->employee_id !== null) {
            return [
                'subject_type' => SubjectType::Employee,
                'subject_key' => SubjectType::Employee->key($assignment->employee_id),
                'employee_id' => $assignment->employee_id,
                'organization_unit_id' => $assignment->organization_unit_id,
                'subject_code' => $assignment->subject_code,
                'subject_name' => $assignment->subject_name,
                'unit_code' => $assignment->unit_code,
                'unit_name' => $assignment->unit_name,
            ];
        }

        // The unit of the KPI's level on the subject's own hierarchy; when the
        // subject sits above that level, its own unit.
        $unit = ['id' => $assignment->organization_unit_id, 'code' => $assignment->unit_code, 'name' => $assignment->unit_name];

        foreach ($assignment->unit_path as $node) {
            if ($node['type'] === $definition->evaluation_subject) {
                $unit = $node;
            }
        }

        return [
            'subject_type' => SubjectType::Unit,
            'subject_key' => SubjectType::Unit->key($unit['id']),
            'employee_id' => null,
            'organization_unit_id' => $unit['id'],
            'subject_code' => $unit['code'],
            'subject_name' => $unit['name'],
            'unit_code' => $unit['code'],
            'unit_name' => $unit['name'],
        ];
    }
}
