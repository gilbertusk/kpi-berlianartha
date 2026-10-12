<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Infrastructure\Console;

use App\Modules\KpiGovernance\Application\CandidateImporter;
use App\Modules\Shared\Application\Audit\Actor;
use Illuminate\Console\Command;

final class ImportKpiCandidatesCommand extends Command
{
    protected $signature = 'kpi:import-candidates';

    protected $description = 'Import the PRD Lampiran A KPI candidates as DRAFT definitions (never published)';

    public function handle(CandidateImporter $importer): int
    {
        $result = $importer->import(Actor::system('console', 'kpi:import-candidates'));

        $this->components->info("Candidates created: {$result['created']}, already present: {$result['existing']}.");

        if ($result['missing_units'] !== []) {
            $this->components->warn('Reference teams not found (run OrganizationReferenceSeeder first): '.implode(', ', $result['missing_units']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
