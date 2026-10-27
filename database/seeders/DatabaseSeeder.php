<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Only the PRD §4 reference structure is seeded everywhere. No approver,
     * weight, target, formula, or real person is seeded until the related
     * decisions are approved (docs/product/DECISION_REGISTER.md). Fictitious
     * [DEV] accounts are added in the local environment only. Model events
     * stay enabled: public ULIDs are assigned on creating.
     */
    public function run(): void
    {
        $this->call(OrganizationReferenceSeeder::class);

        if (app()->environment('local')) {
            $this->call(DevelopmentIdentitySeeder::class);
            // PRD candidates as DRAFT only; elsewhere HR runs kpi:import-candidates.
            $this->call(KpiCandidateSeeder::class);
            $this->call(DevelopmentDemoSeeder::class);
        }
    }
}
