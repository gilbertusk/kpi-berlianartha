<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\KpiGovernance\Application\CandidateImporter;
use App\Modules\Shared\Application\Audit\Actor;
use Illuminate\Database\Seeder;

/**
 * Safe seed: PRD candidates become DRAFT definitions labelled as candidates.
 * Nothing is published, weighted, or targeted.
 */
final class KpiCandidateSeeder extends Seeder
{
    public function run(CandidateImporter $importer): void
    {
        $result = $importer->import(Actor::system('seeder', self::class));

        $this->command->info("Kandidat KPI PRD: {$result['created']} dibuat, {$result['existing']} sudah ada (status DRAFT).");
    }
}
