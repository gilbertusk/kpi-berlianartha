<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application\Adapters;

use App\Models\User;
use App\Modules\Integration\Application\Connection;
use App\Modules\Integration\Application\InboundAdapter;
use App\Modules\Integration\Application\StagedTarget;
use App\Modules\Integration\Domain\Manifest;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Production\Application\ProductionImportService;
use App\Modules\Production\Application\ProductionQuery;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;

/**
 * Production, packing, delivery, warehouse, work order, and inspection
 * files go through the Step 15 staged batch import.
 */
final readonly class ProductionBatchAdapter implements InboundAdapter
{
    public function __construct(private ProductionImportService $imports) {}

    public function stage(Connection $connection, Manifest $manifest, string $path, User $runAs, Actor $actor): StagedTarget
    {
        $kind = BatchKind::tryFrom((string) $connection->kind) ?? throw new BusinessRuleViolation('invalid_connection', 'Jenis batch koneksi tidak dikenal.');
        $unitId = OrganizationUnit::query()->where('code', $manifest->unit)->value('id') ?? throw new BusinessRuleViolation('unknown_unit', 'Team pada manifest tidak dikenal.');
        // The batch checks reject and rework totals only for production actuals; other kinds are reconciled by the runner.
        $controls = array_intersect_key($manifest->controlTotals, array_flip($kind === BatchKind::Production ? ['quantity', 'reject', 'rework'] : ['quantity']));
        $batch = $this->imports->stage($actor, $runAs, (int) $unitId, $kind, $manifest->sourceSystem, $manifest->coverageDate, $manifest->sourceExtractedAt, $manifest->file, $path, 'csv', $controls);

        return $this->target($batch);
    }

    public function commit(StagedTarget $target, User $runAs, Actor $actor): void
    {
        $this->imports->commit($actor, $runAs, $target->id);
    }

    private function target(ProductionBatch $batch): StagedTarget
    {
        $totals = [];

        if ($batch->error_count === 0) {
            $sums = ProductionQuery::totals($batch);

            foreach (['quantity', 'reject', 'rework'] as $key) {
                $totals[$key] = count($sums[$key]) > 1 ? null : (array_values($sums[$key])[0] ?? '0.000');
            }
        }

        return new StagedTarget('production_batch', $batch->id, $batch->public_id, $batch->status, $batch->row_count, $batch->error_count, $totals, $batch->wasRecentlyCreated);
    }
}
