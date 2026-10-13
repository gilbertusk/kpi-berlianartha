<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 09 — immutable scorecard calculation snapshots.
 *
 * Every calculation is a new row that records its complete input, the
 * policy and engine version, every intermediate value, and the reason. A
 * recalculation never overwrites an earlier one: database triggers
 * (PostgreSQL and SQLite) reject UPDATE and DELETE on both tables, also for
 * direct SQL.
 *
 * Precision (ADR-0008): weights numeric(7,4); target, KPI score,
 * contribution, contribution total, and final score numeric(18,4), each
 * already rounded by a signed rule to at most four decimals. Measured value
 * and achievement can be non-terminating rationals, so they are kept as
 * exact text in the trace and as display text in their columns.
 */
return new class extends Migration
{
    private const TABLES = ['scorecard_calculations', 'scorecard_calculation_lines'];

    public function up(): void
    {
        Schema::create('scorecard_calculations', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('assessment_period_id')->constrained('assessment_periods')->restrictOnDelete();
            $table->foreignId('period_assignment_id')->constrained('period_assignments')->restrictOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->foreignId('previous_calculation_id')->nullable()->constrained('scorecard_calculations')->restrictOnDelete();
            $table->unsignedSmallInteger('engine_version');
            $table->string('policy_key', 60);
            $table->unsignedInteger('policy_version');
            $table->string('policy_signoff_reference', 255);
            $table->char('policy_hash', 64);
            $table->json('policy');
            $table->char('input_hash', 64);
            $table->json('inputs');
            $table->string('status', 20);
            $table->json('blockers');
            $table->decimal('assigned_weight', 7, 4);
            $table->decimal('counted_weight', 7, 4);
            $table->decimal('excluded_weight', 7, 4);
            $table->decimal('contribution_total', 18, 4)->nullable();
            $table->decimal('final_score', 18, 4)->nullable();
            $table->string('rating_key', 40)->nullable();
            $table->string('rating_label', 80)->nullable();
            $table->text('reason');
            $table->foreignId('calculated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('calculated_at', 6);
            $table->timestampsTz(6);

            $table->unique(['period_assignment_id', 'sequence_number'], 'scorecard_calculations_sequence_unique');
            $table->index(['assessment_period_id', 'period_assignment_id']);
            // A policy version used once must keep its content everywhere.
            $table->index(['policy_key', 'policy_version']);
        });

        Schema::create('scorecard_calculation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scorecard_calculation_id')->constrained('scorecard_calculations')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('period_target_id')->nullable()->constrained('period_targets')->restrictOnDelete();
            $table->foreignId('kpi_definition_version_id')->constrained('kpi_definition_versions')->restrictOnDelete();
            $table->string('kpi_code', 60);
            $table->string('kpi_name', 160);
            $table->string('component', 120)->nullable();
            $table->decimal('weight', 7, 4);
            $table->boolean('is_mandatory');
            $table->string('strategy_key', 40)->nullable();
            $table->unsignedInteger('strategy_version')->nullable();
            $table->string('aggregation', 30)->nullable();
            $table->string('data_status', 40);
            $table->string('note', 60)->nullable();
            $table->decimal('target_value', 18, 4)->nullable();
            $table->string('measured_value', 64)->nullable();
            $table->string('achievement', 64)->nullable();
            $table->decimal('kpi_score', 18, 4)->nullable();
            $table->decimal('contribution', 18, 4)->nullable();
            $table->json('details');
            $table->timestampsTz(6);

            $table->unique(['scorecard_calculation_id', 'line_number'], 'scorecard_calculation_lines_unique');
            $table->index('period_target_id');
        });

        $this->createGuards();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_immutable_guard ON {$table}");
            }

            DB::unprepared('DROP FUNCTION IF EXISTS reject_scorecard_calculation_change()');
        }

        Schema::dropIfExists('scorecard_calculation_lines');
        Schema::dropIfExists('scorecard_calculations');
    }

    private function createGuards(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_scorecard_calculation_change()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Scorecard calculations are immutable (%)', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            foreach (self::TABLES as $table) {
                DB::statement("CREATE TRIGGER {$table}_immutable_guard BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_scorecard_calculation_change()");
            }

            DB::statement("ALTER TABLE scorecard_calculations ADD CONSTRAINT scorecard_calculations_status_check CHECK (status IN ('complete', 'incomplete'))");
            DB::statement("ALTER TABLE scorecard_calculations ADD CONSTRAINT scorecard_calculations_final_check CHECK (status = 'incomplete' OR (final_score IS NOT NULL AND rating_key IS NOT NULL))");

            return;
        }

        if ($driver !== 'sqlite') {
            throw new RuntimeException('Step 09 immutability guards require PostgreSQL or SQLite.');
        }

        foreach (self::TABLES as $table) {
            DB::statement("CREATE TRIGGER {$table}_update_guard BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'Scorecard calculations are immutable'); END;");
            DB::statement("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Scorecard calculations are immutable'); END;");
        }
    }
};
