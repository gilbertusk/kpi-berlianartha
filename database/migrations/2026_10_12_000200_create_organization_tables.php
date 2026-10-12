<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 05 — effective-dated organization hierarchy and employee assignments
 * (ADR-0006). Names and parents live in versions so a rename or re-parent
 * never rewrites the structure seen by an earlier date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_units', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 40)->unique();
            $table->string('unit_type', 20);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->index(['unit_type', 'effective_from']);
        });

        Schema::create('organization_unit_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->foreignId('parent_unit_id')->nullable()->constrained('organization_units')->restrictOnDelete();
            $table->string('name', 120);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz(6);

            $table->unique(['organization_unit_id', 'effective_from']);
            $table->index(['parent_unit_id', 'effective_from']);
        });

        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('employee_number', 40)->unique();
            $table->string('full_name', 160);
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);
        });

        Schema::create('employee_assignments', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->string('assignment_type', 20);
            $table->string('position_title', 120)->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('end_reason')->nullable();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->index(['employee_id', 'assignment_type', 'effective_from']);
            $table->index(['organization_unit_id', 'effective_from']);
        });

        $this->addPeriodChecks(['organization_units', 'organization_unit_versions', 'employee_assignments']);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_assignments');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('organization_unit_versions');
        Schema::dropIfExists('organization_units');
    }

    /**
     * Half-open periods must not be empty. SQLite cannot add constraints to an
     * existing table, so the application services remain the primary guard.
     *
     * @param  list<string>  $tables
     */
    private function addPeriodChecks(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($tables as $table) {
            DB::statement(sprintf(
                'ALTER TABLE %1$s ADD CONSTRAINT %1$s_effective_period_check CHECK (effective_until IS NULL OR effective_until > effective_from)',
                $table,
            ));
        }
    }
};
