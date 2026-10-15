<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 15 — production, PPIC, and warehouse read model.
 *
 * The KPI system is not an ERP, MES, or WMS. Teams load what their source
 * systems report as batches: production actuals and packing per operational
 * date, deliveries per shipping date, and stock and work orders as of a
 * date. A batch is staged, checked, and committed whole; once final it never
 * changes. Late-arriving data or a source correction for the same date is a
 * new batch version beside the old one; the latest committed version is the
 * current one. The PPIC board keeps its own status history, append-only.
 *
 * Quantities are numeric(18,3) in the source unit; nothing is converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_batches', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('source_system', 40);
            $table->date('coverage_date');
            $table->unsignedInteger('version');
            $table->char('content_hash', 64);
            $table->string('original_name', 160);
            $table->string('status', 20);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->unsignedInteger('missing_reason_count')->default(0);
            $table->string('calendar_version', 40)->nullable();
            $table->timestampTz('source_extracted_at', 6);
            $table->decimal('control_quantity_total', 18, 3)->nullable();
            $table->decimal('control_reject_total', 18, 3)->nullable();
            $table->decimal('control_rework_total', 18, 3)->nullable();
            $table->foreignId('staged_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('committed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('committed_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['organization_unit_id', 'kind', 'source_system', 'coverage_date', 'version'], 'production_batches_version_unique');
            $table->index(['kind', 'status', 'coverage_date']);
        });

        // The same file is staged once for a team, kind, source, and date. A
        // reference snapshot (stock, work orders) may repeat its bytes on
        // another date, and a superseded content may come back as a new version.
        DB::statement("CREATE UNIQUE INDEX production_batches_staged_content_unique ON production_batches (organization_unit_id, kind, source_system, coverage_date, content_hash) WHERE status = 'staged'");

        Schema::create('production_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_batch_id')->constrained('production_batches')->restrictOnDelete();
            $table->unsignedInteger('source_line');
            $table->string('record_type', 20);
            $table->string('event_key', 255);
            $table->string('work_order', 60)->nullable();
            $table->string('product_code', 60)->nullable();
            $table->string('machine_code', 60)->nullable();
            $table->string('customer_code', 60)->nullable();
            $table->string('shift_code', 20)->nullable();
            $table->date('operational_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('ended_at', 6)->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 18, 3)->nullable();
            $table->decimal('reject_quantity', 18, 3)->nullable();
            $table->decimal('rework_quantity', 18, 3)->nullable();
            $table->unsignedInteger('downtime_minutes')->nullable();
            $table->string('reason_code', 60)->nullable();
            $table->string('defect_code', 60)->nullable();
            $table->string('location_category', 30)->nullable();
            $table->json('details')->nullable();
            $table->char('row_hash', 64);
            $table->timestampsTz(6);

            $table->unique(['production_batch_id', 'record_type', 'event_key'], 'production_records_event_unique');
            $table->index(['production_batch_id', 'work_order']);
            $table->index(['record_type', 'event_key'], 'production_records_event_lookup');
        });

        Schema::create('ppic_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->string('work_order', 60);
            $table->unsignedInteger('sequence');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->date('need_date')->nullable();
            $table->boolean('was_overdue');
            $table->text('reason')->nullable();
            $table->foreignId('production_batch_id')->constrained('production_batches')->restrictOnDelete();
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at', 6);
            $table->timestampsTz(6);

            $table->unique(['organization_unit_id', 'work_order', 'sequence'], 'ppic_status_changes_sequence_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_production_batch_change()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'A final production batch is not changed (%)', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;

                -- PostgreSQL does not allow a subquery in a trigger WHEN condition,
                -- so the parent batch status is read inside the function.
                CREATE OR REPLACE FUNCTION reject_production_record_change()
                RETURNS trigger AS $$
                DECLARE
                    batch_status varchar;
                BEGIN
                    SELECT status INTO batch_status FROM production_batches
                        WHERE id = CASE WHEN TG_OP = 'INSERT' THEN NEW.production_batch_id ELSE OLD.production_batch_id END;

                    IF batch_status IS DISTINCT FROM 'staged' THEN
                        RAISE EXCEPTION 'A final production batch is not changed (%)', TG_TABLE_NAME;
                    END IF;

                    -- A row is never moved into a final batch either.
                    IF TG_OP = 'UPDATE' THEN
                        SELECT status INTO batch_status FROM production_batches WHERE id = NEW.production_batch_id;

                        IF batch_status IS DISTINCT FROM 'staged' THEN
                            RAISE EXCEPTION 'A final production batch is not changed (%)', TG_TABLE_NAME;
                        END IF;
                    END IF;

                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE OR REPLACE FUNCTION reject_ppic_status_change()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'PPIC status history is append-only';
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            DB::statement("CREATE TRIGGER production_batches_final_guard BEFORE UPDATE OR DELETE ON production_batches FOR EACH ROW WHEN (OLD.status <> 'staged') EXECUTE FUNCTION reject_production_batch_change()");
            DB::statement('CREATE TRIGGER production_records_final_guard BEFORE INSERT OR UPDATE OR DELETE ON production_records FOR EACH ROW EXECUTE FUNCTION reject_production_record_change()');
            DB::statement('CREATE TRIGGER ppic_status_changes_append_only BEFORE UPDATE OR DELETE ON ppic_status_changes FOR EACH ROW EXECUTE FUNCTION reject_ppic_status_change()');
            DB::statement("ALTER TABLE production_batches ADD CONSTRAINT production_batches_status_check CHECK (status IN ('staged', 'committed', 'rejected'))");
            DB::statement("ALTER TABLE production_batches ADD CONSTRAINT production_batches_kind_check CHECK (kind IN ('production', 'packing', 'delivery', 'warehouse', 'work_orders'))");
            DB::statement('ALTER TABLE production_records ADD CONSTRAINT production_records_quantity_check CHECK ((quantity IS NULL OR quantity >= 0) AND (reject_quantity IS NULL OR reject_quantity >= 0) AND (rework_quantity IS NULL OR rework_quantity >= 0))');
            DB::statement("ALTER TABLE ppic_status_changes ADD CONSTRAINT ppic_status_changes_status_check CHECK (to_status IN ('WAITING_MATERIAL', 'WAITING_DESIGN', 'READY_FOR_PRODUCTION', 'IN_PRODUCTION', 'QC', 'PACKING', 'READY_TO_DELIVER', 'COMPLETED'))");

            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Step 15 guards require PostgreSQL or SQLite.');
        }

        DB::statement("CREATE TRIGGER production_batches_update_guard BEFORE UPDATE ON production_batches WHEN OLD.status <> 'staged' BEGIN SELECT RAISE(ABORT, 'A final production batch is not changed'); END;");
        DB::statement("CREATE TRIGGER production_batches_delete_guard BEFORE DELETE ON production_batches WHEN OLD.status <> 'staged' BEGIN SELECT RAISE(ABORT, 'A final production batch is not changed'); END;");
        DB::statement("CREATE TRIGGER production_records_insert_guard BEFORE INSERT ON production_records WHEN (SELECT status FROM production_batches WHERE id = NEW.production_batch_id) IS NOT 'staged' BEGIN SELECT RAISE(ABORT, 'A final production batch is not changed'); END;");
        DB::statement("CREATE TRIGGER production_records_update_guard BEFORE UPDATE ON production_records WHEN (SELECT status FROM production_batches WHERE id = OLD.production_batch_id) <> 'staged' OR (SELECT status FROM production_batches WHERE id = NEW.production_batch_id) IS NOT 'staged' BEGIN SELECT RAISE(ABORT, 'A final production batch is not changed'); END;");
        DB::statement("CREATE TRIGGER production_records_delete_guard BEFORE DELETE ON production_records WHEN (SELECT status FROM production_batches WHERE id = OLD.production_batch_id) <> 'staged' BEGIN SELECT RAISE(ABORT, 'A final production batch is not changed'); END;");
        DB::statement("CREATE TRIGGER ppic_status_changes_update_guard BEFORE UPDATE ON ppic_status_changes BEGIN SELECT RAISE(ABORT, 'PPIC status history is append-only'); END;");
        DB::statement("CREATE TRIGGER ppic_status_changes_delete_guard BEFORE DELETE ON ppic_status_changes BEGIN SELECT RAISE(ABORT, 'PPIC status history is append-only'); END;");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS production_batches_final_guard ON production_batches');
            DB::statement('DROP TRIGGER IF EXISTS production_records_final_guard ON production_records');
            DB::statement('DROP TRIGGER IF EXISTS ppic_status_changes_append_only ON ppic_status_changes');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_production_record_change()');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_production_batch_change()');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_ppic_status_change()');
        }

        Schema::dropIfExists('ppic_status_changes');
        Schema::dropIfExists('production_records');
        Schema::dropIfExists('production_batches');
    }
};
