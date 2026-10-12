<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Application;

use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\KpiGovernance\Infrastructure\PrdCandidateCatalog;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Application\Audit\Actor;

/**
 * Imports the PRD candidate list as DRAFT definitions with origin
 * "prd_candidate". Only the name and owning team are known, so a candidate
 * can never be published until HR completes and signs off every field.
 * Idempotent by candidate code.
 */
final readonly class CandidateImporter
{
    public function __construct(private KpiDefinitionService $definitions) {}

    /**
     * @return array{created: int, existing: int, missing_units: list<string>}
     */
    public function import(Actor $actor): array
    {
        $created = 0;
        $existing = 0;
        $missingUnits = [];

        foreach (PrdCandidateCatalog::CANDIDATES as $teamCode => $names) {
            $unitId = OrganizationUnit::query()->where('code', $teamCode)->value('id');

            if ($unitId === null) {
                $missingUnits[] = $teamCode;

                continue;
            }

            foreach ($names as $index => $name) {
                $code = PrdCandidateCatalog::codeFor($teamCode, $index + 1);

                if (KpiDefinition::query()->where('code', $code)->exists()) {
                    $existing++;

                    continue;
                }

                $this->definitions->createDraft(
                    $actor,
                    $code,
                    (int) $unitId,
                    ['name' => $name],
                    KpiDefinition::ORIGIN_PRD_CANDIDATE,
                    'Kandidat dari '.PrdCandidateCatalog::SOURCE.'; belum disetujui.',
                );
                $created++;
            }
        }

        return ['created' => $created, 'existing' => $existing, 'missing_units' => $missingUnits];
    }
}
