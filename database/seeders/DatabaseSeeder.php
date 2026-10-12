<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Intentionally empty: no hard-coded accounts, passwords, approvers,
        // weights, or business seed data until the related decisions are
        // approved (docs/product/DECISION_REGISTER.md). Local users: README.md.
    }
}
