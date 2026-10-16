<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 16 — Quality Control: inspection batches and NCR/CAPA.
 *
 * Inspections arrive as production batches of the new kind `inspection`. An
 * NCR refers to its source (an inspection row, or a complaint, return, or
 * internal finding by reference) without copying it, and moves through the
 * PRD §10.3 stages. Each stage keeps its PIC, due date, content, and
 * evidence; a completed stage never changes. Every move and correction is an
 * append-only event, and an NCR is never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_ncrs', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('number', 20)->unique();
            $table->foreignId('qc_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->foreignId('responsible_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->string('stage', 30);
            $table->unsignedInteger('cycle')->default(1);
            $table->string('source_type', 20);
            $table->string('source_reference', 200);
            $table->foreignId('production_record_id')->nullable()->constrained('production_records')->restrictOnDelete();
            $table->string('category_code', 60);
            $table->string('severity', 30)->nullable();
            $table->text('description');
            $table->decimal('quantity', 18, 3)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('process_code', 60)->nullable();
            $table->string('work_order', 60)->nullable();
            $table->string('product_code', 60)->nullable();
            $table->string('lot', 60)->nullable();
            $table->string('machine_code', 60)->nullable();
            $table->string('shift_code', 20)->nullable();
            $table->timestampTz('detected_at', 6);
            $table->foreignId('repeat_of_ncr_id')->nullable()->constrained('quality_ncrs')->restrictOnDelete();
            $table->foreignId('kpi_definition_version_id')->nullable()->constrained('kpi_definition_versions')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('raised_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at', 6)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            // One NCR per defect category of a source record: the same defect is never raised twice.
            $table->unique(['source_type', 'source_reference', 'category_code'], 'quality_ncrs_source_unique');
            $table->index(['stage', 'responsible_unit_id']);
        });

        Schema::create('quality_ncr_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quality_ncr_id')->constrained('quality_ncrs')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->unsignedInteger('cycle');
            $table->string('stage', 30);
            $table->foreignId('pic_user_id')->constrained('users')->restrictOnDelete();
            $table->date('due_date');
            $table->timestampTz('entered_at', 6);
            $table->text('content')->nullable();
            $table->string('root_cause_method', 30)->nullable();
            $table->text('preventive_action')->nullable();
            $table->string('effectiveness_result', 20)->nullable();
            $table->text('evidence_reference')->nullable();
            $table->boolean('evidence_restricted')->default(false);
            $table->string('outcome', 20)->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['quality_ncr_id', 'sequence'], 'quality_ncr_stages_sequence_unique');
        });

        // An NCR has at most one open stage.
        DB::statement('CREATE UNIQUE INDEX quality_ncr_stages_open_unique ON quality_ncr_stages (quality_ncr_id) WHERE completed_at IS NULL');

        Schema::create('quality_ncr_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quality_ncr_id')->constrained('quality_ncrs')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('action', 20);
            $table->string('from_stage', 30)->nullable();
            $table->string('to_stage', 30)->nullable();
            $table->json('changes')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at', 6);
            $table->timestampsTz(6);

            $table->unique(['quality_ncr_id', 'sequence'], 'quality_ncr_events_sequence_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_quality_history_change()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Quality history is not changed or deleted (%)', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            DB::statement('CREATE TRIGGER quality_ncrs_no_delete BEFORE DELETE ON quality_ncrs FOR EACH ROW EXECUTE FUNCTION reject_quality_history_change()');
            DB::statement('CREATE TRIGGER quality_ncr_stages_no_delete BEFORE DELETE ON quality_ncr_stages FOR EACH ROW EXECUTE FUNCTION reject_quality_history_change()');
            DB::statement('CREATE TRIGGER quality_ncr_stages_completed_guard BEFORE UPDATE ON quality_ncr_stages FOR EACH ROW WHEN (OLD.completed_at IS NOT NULL) EXECUTE FUNCTION reject_quality_history_change()');
            DB::statement('CREATE TRIGGER quality_ncr_events_append_only BEFORE UPDATE OR DELETE ON quality_ncr_events FOR EACH ROW EXECUTE FUNCTION reject_quality_history_change()');
            DB::statement("ALTER TABLE quality_ncrs ADD CONSTRAINT quality_ncrs_stage_check CHECK (stage IN ('TEMUAN', 'CONTAINMENT', 'ROOT_CAUSE', 'CORRECTIVE_ACTION', 'VERIFICATION', 'CLOSED'))");
            DB::statement("ALTER TABLE quality_ncrs ADD CONSTRAINT quality_ncrs_source_check CHECK (source_type IN ('inspection', 'complaint', 'return', 'internal'))");
            DB::statement("ALTER TABLE quality_ncrs ADD CONSTRAINT quality_ncrs_closed_check CHECK ((stage = 'CLOSED') = (closed_at IS NOT NULL))");
            DB::statement("ALTER TABLE quality_ncr_stages ADD CONSTRAINT quality_ncr_stages_stage_check CHECK (stage IN ('TEMUAN', 'CONTAINMENT', 'ROOT_CAUSE', 'CORRECTIVE_ACTION', 'VERIFICATION'))");

            // Step 16 adds the inspection kind to the Step 15 batches.
            DB::statement('ALTER TABLE production_batches DROP CONSTRAINT production_batches_kind_check');
            DB::statement("ALTER TABLE production_batches ADD CONSTRAINT production_batches_kind_check CHECK (kind IN ('production', 'packing', 'delivery', 'warehouse', 'work_orders', 'inspection'))");

            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Step 16 guards require PostgreSQL or SQLite.');
        }

        DB::statement("CREATE TRIGGER quality_ncrs_no_delete BEFORE DELETE ON quality_ncrs BEGIN SELECT RAISE(ABORT, 'Quality history is not changed or deleted'); END;");
        DB::statement("CREATE TRIGGER quality_ncr_stages_no_delete BEFORE DELETE ON quality_ncr_stages BEGIN SELECT RAISE(ABORT, 'Quality history is not changed or deleted'); END;");
        DB::statement("CREATE TRIGGER quality_ncr_stages_completed_guard BEFORE UPDATE ON quality_ncr_stages WHEN OLD.completed_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'Quality history is not changed or deleted'); END;");
        DB::statement("CREATE TRIGGER quality_ncr_events_no_update BEFORE UPDATE ON quality_ncr_events BEGIN SELECT RAISE(ABORT, 'Quality history is not changed or deleted'); END;");
        DB::statement("CREATE TRIGGER quality_ncr_events_no_delete BEFORE DELETE ON quality_ncr_events BEGIN SELECT RAISE(ABORT, 'Quality history is not changed or deleted'); END;");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS quality_ncrs_no_delete ON quality_ncrs');
            DB::statement('DROP TRIGGER IF EXISTS quality_ncr_stages_no_delete ON quality_ncr_stages');
            DB::statement('DROP TRIGGER IF EXISTS quality_ncr_stages_completed_guard ON quality_ncr_stages');
            DB::statement('DROP TRIGGER IF EXISTS quality_ncr_events_append_only ON quality_ncr_events');
        }

        Schema::dropIfExists('quality_ncr_events');
        Schema::dropIfExists('quality_ncr_stages');
        Schema::dropIfExists('quality_ncrs');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_quality_history_change()');
            DB::statement('ALTER TABLE production_batches DROP CONSTRAINT production_batches_kind_check');
            DB::statement("ALTER TABLE production_batches ADD CONSTRAINT production_batches_kind_check CHECK (kind IN ('production', 'packing', 'delivery', 'warehouse', 'work_orders'))");
        }
    }
};
