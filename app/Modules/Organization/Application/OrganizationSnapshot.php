<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\UnitType;

/**
 * Read-only hierarchy for one business date. Scope checks use it to decide
 * whether a unit is inside another unit's subtree.
 */
final class OrganizationSnapshot
{
    /** @var array<int, list<int>> */
    private array $children = [];

    /** @var array<int, list<int>> */
    private array $descendantCache = [];

    /**
     * @param  array<int, UnitNode>  $units  keyed by unit id
     */
    public function __construct(
        public readonly string $businessDate,
        private readonly array $units,
    ) {
        foreach ($units as $unit) {
            if ($unit->parentId !== null) {
                $this->children[$unit->parentId][] = $unit->id;
            }
        }
    }

    public function unit(int $id): ?UnitNode
    {
        return $this->units[$id] ?? null;
    }

    /**
     * @return list<UnitNode>
     */
    public function all(): array
    {
        $units = array_values($this->units);
        usort($units, static fn (UnitNode $a, UnitNode $b): int => [$a->type !== UnitType::Company, $a->code] <=> [$b->type !== UnitType::Company, $b->code]);

        return $units;
    }

    /**
     * @return list<UnitNode>
     */
    public function ofType(UnitType $type): array
    {
        return array_values(array_filter($this->all(), static fn (UnitNode $unit): bool => $unit->type === $type));
    }

    /**
     * The unit itself plus every unit below it on this date.
     *
     * @return list<int>
     */
    public function subtreeIds(int $rootId): array
    {
        if (isset($this->descendantCache[$rootId])) {
            return $this->descendantCache[$rootId];
        }

        if (! isset($this->units[$rootId])) {
            return $this->descendantCache[$rootId] = [];
        }

        $ids = [];
        $queue = [$rootId];

        while ($queue !== []) {
            $id = array_shift($queue);

            if (in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;
            array_push($queue, ...($this->children[$id] ?? []));
        }

        return $this->descendantCache[$rootId] = $ids;
    }

    public function contains(int $rootId, int $unitId): bool
    {
        return in_array($unitId, $this->subtreeIds($rootId), true);
    }

    /**
     * Human readable path such as "Produksi › Woven".
     */
    public function pathLabel(int $unitId): string
    {
        $names = [];
        $current = $this->unit($unitId);

        while ($current !== null && $current->type !== UnitType::Company) {
            array_unshift($names, $current->name);
            $current = $current->parentId === null ? null : $this->unit($current->parentId);
        }

        return $names === [] ? ($this->unit($unitId)->name ?? '—') : implode(' › ', $names);
    }
}
