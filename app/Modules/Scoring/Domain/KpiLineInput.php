<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

/**
 * One KPI of a scorecard with everything its score depends on: the pinned
 * definition version (strategy, parameters, aggregation, valid range), the
 * template weight, the final target, and the actual entries. Nothing here is
 * read from "current" configuration, so the same input always reproduces
 * the same line.
 */
final readonly class KpiLineInput
{
    /**
     * @param  string  $weight  percentage points, canonical decimal string
     * @param  array<string, mixed>  $parameters  normalized score parameters of the definition version
     * @param  list<EntryInput>  $entries
     */
    public function __construct(
        public string $kpiCode,
        public string $kpiName,
        public int $definitionVersionId,
        public ?string $component,
        public string $weight,
        public bool $isMandatory,
        public ?string $targetReference,
        public ?string $targetValue,
        public ?string $strategyKey,
        public ?int $strategyVersion,
        public array $parameters,
        public ?string $aggregation,
        public ?string $minValidValue,
        public ?string $maxValidValue,
        public array $entries,
    ) {}

    public function strategyReference(): string
    {
        return ($this->strategyKey ?? '—').'@'.($this->strategyVersion ?? '—');
    }

    /**
     * @return list<EntryInput> by observation window
     */
    public function sortedEntries(): array
    {
        $entries = $this->entries;
        usort($entries, static fn (EntryInput $a, EntryInput $b): int => strcmp($a->sortKey(), $b->sortKey()));

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kpi_code' => $this->kpiCode,
            'kpi_name' => $this->kpiName,
            'definition_version_id' => $this->definitionVersionId,
            'component' => $this->component,
            'weight' => $this->weight,
            'mandatory' => $this->isMandatory,
            'target_reference' => $this->targetReference,
            'target_value' => $this->targetValue,
            'strategy' => $this->strategyKey,
            'strategy_version' => $this->strategyVersion,
            'parameters' => $this->parameters,
            'aggregation' => $this->aggregation,
            'min_valid_value' => $this->minValidValue,
            'max_valid_value' => $this->maxValidValue,
            'entries' => array_map(static fn (EntryInput $entry): array => $entry->toArray(), $this->sortedEntries()),
        ];
    }
}
