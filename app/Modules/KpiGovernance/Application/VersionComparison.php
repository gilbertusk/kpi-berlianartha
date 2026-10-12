<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Application;

use App\Modules\KpiGovernance\Domain\DefinitionContent;
use App\Modules\KpiGovernance\Domain\ItemApplicability;
use App\Modules\KpiGovernance\Domain\Scoring\ScoreStrategyRegistry;
use App\Modules\KpiGovernance\Domain\Vocabulary;
use App\Modules\KpiGovernance\Domain\Weight;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateItem;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateVersion;

/**
 * Field-by-field differences between two versions, presented with labels
 * and the reason recorded on the newer version.
 */
final readonly class VersionComparison
{
    private const VOCABULARY_LABELS = [
        'evaluation_subject' => Vocabulary::EVALUATION_SUBJECTS,
        'unit' => Vocabulary::UNITS,
        'value_type' => Vocabulary::VALUE_TYPES,
        'direction' => Vocabulary::DIRECTIONS,
        'frequency' => Vocabulary::FREQUENCIES,
        'aggregation' => Vocabulary::AGGREGATIONS,
        'input_method' => Vocabulary::INPUT_METHODS,
        'evidence_requirement' => Vocabulary::EVIDENCE_REQUIREMENTS,
    ];

    public function __construct(private ScoreStrategyRegistry $strategies) {}

    /**
     * @return list<array{field: string, label: string, before: string, after: string}>
     */
    public function definitions(KpiDefinitionVersion $from, KpiDefinitionVersion $to): array
    {
        $before = $from->content()->toArray();
        $after = $to->content()->toArray();
        $changes = [];

        foreach (DefinitionContent::FIELDS as $field => $label) {
            $old = $this->present($field, $before[$field], $before);
            $new = $this->present($field, $after[$field], $after);

            if ($old !== $new) {
                $changes[] = ['field' => $field, 'label' => $label, 'before' => $old, 'after' => $new];
            }
        }

        return $changes;
    }

    /**
     * @return array{header: list<array{field: string, label: string, before: string, after: string}>, items: list<array{kpi: string, change: string, before: string, after: string}>}
     */
    public function templates(KpiTemplateVersion $from, KpiTemplateVersion $to): array
    {
        $header = [];

        foreach (['notes' => 'Catatan', 'signoff_reference' => 'Referensi sign-off', 'effective_from' => 'Berlaku mulai'] as $field => $label) {
            $old = (string) ($from->{$field} ?? '—');
            $new = (string) ($to->{$field} ?? '—');

            if ($old !== $new) {
                $header[] = ['field' => $field, 'label' => $label, 'before' => $old, 'after' => $new];
            }
        }

        $old = $this->itemsByDefinition($from);
        $new = $this->itemsByDefinition($to);
        $items = [];

        foreach ($new as $definitionId => $item) {
            if (! isset($old[$definitionId])) {
                $items[] = ['kpi' => $item['kpi'], 'change' => 'ditambahkan', 'before' => '—', 'after' => $item['summary']];
            } elseif ($old[$definitionId]['summary'] !== $item['summary']) {
                $items[] = ['kpi' => $item['kpi'], 'change' => 'diubah', 'before' => $old[$definitionId]['summary'], 'after' => $item['summary']];
            }
        }

        foreach ($old as $definitionId => $item) {
            if (! isset($new[$definitionId])) {
                $items[] = ['kpi' => $item['kpi'], 'change' => 'dihapus', 'before' => $item['summary'], 'after' => '—'];
            }
        }

        return ['header' => $header, 'items' => $items];
    }

    /**
     * @return array<int, array{kpi: string, summary: string}>
     */
    private function itemsByDefinition(KpiTemplateVersion $version): array
    {
        $result = [];

        foreach ($version->items()->with('definitionVersion')->get() as $item) {
            /** @var KpiTemplateItem $item */
            $definition = $item->definitionVersion;
            $applicability = ItemApplicability::fromArray($item->applicability);

            $result[$definition->kpi_definition_id] = [
                'kpi' => $definition->name,
                'summary' => sprintf(
                    'v%d, bobot %s%%, %s, urutan %d%s%s',
                    $definition->version_number,
                    Weight::fromString($item->weight)->format(),
                    $item->is_mandatory ? 'wajib' : 'opsional',
                    $item->sort_order,
                    $item->component === null ? '' : ", komponen {$item->component}",
                    $applicability === null ? '' : ', '.$applicability->describe(),
                ),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function present(string $field, mixed $value, array $content): string
    {
        if ($value === null) {
            return '—';
        }

        if (isset(self::VOCABULARY_LABELS[$field]) && is_string($value)) {
            return Vocabulary::label(self::VOCABULARY_LABELS[$field], $value);
        }

        if ($field === 'score_parameters' && is_array($value)) {
            $strategy = $this->strategies->find(
                is_string($content['score_strategy']) ? $content['score_strategy'] : null,
                is_int($content['score_strategy_version']) ? $content['score_strategy_version'] : null,
            );

            return $strategy !== null && $strategy->validate($value) === []
                ? $strategy->describe($value)
                : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
