<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Application;

use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateItem;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\Scoring\Domain\EntryInput;
use App\Modules\Scoring\Domain\KpiLineInput;
use App\Modules\Scoring\Domain\ScorecardInput;
use App\Modules\Target\Application\TargetSlotBuilder;
use App\Modules\Target\Domain\TargetStatus;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTargetVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Assembles the immutable inputs of each subject's scorecard: the template
 * version pinned by the period snapshot, the KPI definition version pinned
 * by each item, the target slot the item maps to (same mapping as
 * TargetSlotBuilder), the latest approved target, and the newest version of
 * every actual. Nothing is read from "current" configuration, so an old
 * period always reproduces the same input.
 */
final class ScorecardInputLoader
{
    /**
     * @param  Collection<int, PeriodAssignment>  $assignments  covered subjects of the period
     * @return array<int, ScorecardInput> keyed by assignment id
     */
    public function load(AssessmentPeriod $period, Collection $assignments): array
    {
        $templateIds = $assignments->pluck('kpi_template_version_id')->filter()->unique()->values()->all();
        $items = KpiTemplateItem::query()
            ->whereIn('kpi_template_version_id', $templateIds === [] ? [0] : $templateIds)
            ->with('definitionVersion.definition')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('kpi_template_version_id');

        $targets = PeriodTarget::query()->where('assessment_period_id', $period->id)->get()
            ->keyBy(fn (PeriodTarget $target): string => "{$target->kpi_definition_version_id}|{$target->subject_key}");
        $finalTargets = $this->finalTargets($period);
        $entries = $this->entries($period);
        $inputs = [];

        foreach ($assignments as $assignment) {
            $lines = [];

            foreach ($items->get((int) $assignment->kpi_template_version_id) ?? [] as $item) {
                $rule = $item->applicabilityRule();

                if ($rule !== null && ! $rule->appliesTo($assignment->position_title)) {
                    continue;
                }

                $definition = $item->definitionVersion;
                $target = $targets->get($definition->id.'|'.TargetSlotBuilder::slotFor($assignment, $definition)['subject_key']);

                $lines[] = new KpiLineInput(
                    kpiCode: $definition->definition->code,
                    kpiName: $definition->name,
                    definitionVersionId: $definition->id,
                    component: $item->component,
                    weight: $item->weight,
                    isMandatory: $item->is_mandatory,
                    targetReference: $target?->public_id,
                    targetValue: $target === null ? null : ($finalTargets[$target->id] ?? null),
                    strategyKey: $definition->score_strategy,
                    strategyVersion: $definition->score_strategy_version,
                    parameters: $definition->score_parameters ?? [],
                    aggregation: $definition->aggregation,
                    minValidValue: $definition->min_valid_value,
                    maxValidValue: $definition->max_valid_value,
                    entries: $target === null ? [] : ($entries[$target->id] ?? []),
                );
            }

            $template = $assignment->template_code === null ? null : "{$assignment->template_code}@{$assignment->template_version_number}";
            $inputs[$assignment->id] = new ScorecardInput($assignment->public_id, $assignment->subject_type->value, $assignment->subject_code, $template, $lines);
        }

        return $inputs;
    }

    /**
     * The latest approved value of each target of the period.
     *
     * @return array<int, string> target id => canonical decimal
     */
    private function finalTargets(AssessmentPeriod $period): array
    {
        $values = [];

        $versions = PeriodTargetVersion::query()
            ->where('status', TargetStatus::Approved->value)
            ->whereIn('period_target_id', PeriodTarget::query()->where('assessment_period_id', $period->id)->select('id'))
            ->orderBy('period_target_id')
            ->orderBy('version_number')
            ->get(['period_target_id', 'target_value']);

        foreach ($versions as $version) {
            $values[$version->period_target_id] = $version->target_value;
        }

        return $values;
    }

    /**
     * The newest version of every actual of the period, per target.
     *
     * @return array<int, list<EntryInput>> target id => entries
     */
    private function entries(AssessmentPeriod $period): array
    {
        $entries = [];

        $versions = KpiActualVersion::query()
            ->whereHas('actual', fn (Builder $actuals) => $actuals->whereIn(
                'period_target_id',
                PeriodTarget::query()->where('assessment_period_id', $period->id)->select('id'),
            ))
            ->whereNotExists(fn (QueryBuilder $newer) => $newer->from('kpi_actual_versions as newer')
                ->whereColumn('newer.kpi_actual_id', 'kpi_actual_versions.kpi_actual_id')
                ->whereColumn('newer.version_number', '>', 'kpi_actual_versions.version_number'))
            ->with('actual')
            ->orderBy('id')
            ->get();

        foreach ($versions as $version) {
            $actual = $version->actual;
            $entries[$actual->period_target_id][] = new EntryInput(
                reference: $actual->public_id,
                version: $version->version_number,
                windowStart: $actual->window_start,
                windowEnd: $actual->window_end,
                status: $version->status->value,
                kind: $version->entry_kind->value,
                value: $version->actual_value,
                isLate: $version->is_late,
            );
        }

        return $entries;
    }
}
