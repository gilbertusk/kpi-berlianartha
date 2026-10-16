<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application\Adapters;

use App\Models\User;
use App\Modules\Accounting\Application\ArImportService;
use App\Modules\Accounting\Application\ArQuery;
use App\Modules\Integration\Application\Connection;
use App\Modules\Integration\Application\InboundAdapter;
use App\Modules\Integration\Application\StagedTarget;
use App\Modules\Integration\Domain\Manifest;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;

/**
 * Receivables files go through the Step 14 staged snapshot import.
 */
final readonly class ReceivablesAdapter implements InboundAdapter
{
    public function __construct(private ArImportService $imports) {}

    public function stage(Connection $connection, Manifest $manifest, string $path, User $runAs, Actor $actor): StagedTarget
    {
        $unitId = OrganizationUnit::query()->where('code', $manifest->unit)->value('id') ?? throw new BusinessRuleViolation('unknown_unit', 'Team pada manifest tidak dikenal.');
        $snapshot = $this->imports->stage(
            $actor, $runAs, (int) $unitId, $manifest->sourceSystem, $manifest->coverageDate, $manifest->file, $path, 'csv',
            $manifest->controlTotals['invoice'] ?? null, $manifest->controlTotals['allocation'] ?? null,
        );
        $totals = [];

        if ($snapshot->error_count === 0) {
            $sums = ArQuery::totals($snapshot->id);
            $totals = ['invoice' => $sums['invoice_total'], 'allocation' => $sums['allocation_total']];
        }

        return new StagedTarget('ar_snapshot', $snapshot->id, $snapshot->public_id, $snapshot->status, $snapshot->row_count, $snapshot->error_count, $totals, $snapshot->wasRecentlyCreated);
    }

    public function commit(StagedTarget $target, User $runAs, Actor $actor): void
    {
        $this->imports->commit($actor, $runAs, $target->id);
    }
}
