<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 08 — KPI actuals, evidence metadata, and staged actual import.
 *
 * Database triggers (PostgreSQL and SQLite) enforce, also for direct SQL:
 * an actual version starts as a draft, follows
 * draft → submitted → verified | rejected → draft, is editable only as a
 * draft, and accepts no change at all once verified. Evidence binaries live
 * in private object storage; these tables hold metadata only (ADR-0004).
 */
return new class extends Migration
{
    private const VERSION_CONTENT_COLUMNS = [
        'kpi_actual_id', 'version_number', 'entry_kind', 'actual_value', 'unit', 'source',
        'actual_import_batch_id', 'note', 'is_late', 'late_reason', 'previous_version_id',
    ];

    public function up(): void
    {
        Schema::create('actual_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('assessment_period_id')->constrained('assessment_periods')->restrictOnDelete();
            $table->string('status', 20);
            $table->string('file_name', 255);
            $table->string('file_format', 10);
            $table->char('file_checksum', 64);
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('valid_count');
            $table->unsignedInteger('unchanged_count');
            $table->unsignedInteger('error_count');
            $table->json('summary')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('committed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('committed_at', 6)->nullable();
            $table->foreignId('discarded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('discarded_at', 6)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->index(['assessment_period_id', 'file_checksum']);
            $table->index(['assessment_period_id', 'status']);
        });

        Schema::create('kpi_actuals', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('period_target_id')->constrained('period_targets')->restrictOnDelete();
            // Observation window: inclusive business dates inside the period.
            $table->date('window_start');
            $table->date('window_end');
            $table->string('source_system', 64)->nullable();
            $table->string('source_reference', 191)->nullable();
            $table->timestampsTz(6);

            $table->unique(['period_target_id', 'window_start', 'window_end'], 'kpi_actuals_window_unique');
            // One source record feeds one actual of a target (NULLs are distinct).
            $table->unique(['period_target_id', 'source_system', 'source_reference'], 'kpi_actuals_source_unique');
        });

        Schema::create('kpi_actual_versions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('kpi_actual_id')->constrained('kpi_actuals')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 20);
            // 'value' carries actual_value (zero is a value); 'not_applicable'
            // carries none. No row at all means missing.
            $table->string('entry_kind', 20);
            $table->decimal('actual_value', 18, 4)->nullable();
            $table->string('unit', 30);
            $table->string('source', 20);
            $table->foreignId('actual_import_batch_id')->nullable()->constrained('actual_import_batches')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->boolean('is_late')->default(false);
            $table->text('late_reason')->nullable();
            $table->foreignId('previous_version_id')->nullable()->constrained('kpi_actual_versions')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at', 6)->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at', 6)->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('rejected_at', 6)->nullable();
            $table->text('reject_reason')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->unique(['kpi_actual_id', 'version_number']);
            $table->index(['kpi_actual_id', 'status']);
            $table->index('actual_import_batch_id');
        });

        Schema::create('evidence_files', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('kpi_actual_id')->constrained('kpi_actuals')->restrictOnDelete();
            $table->foreignId('stored_object_id')->constrained('stored_object_metadata')->restrictOnDelete();
            $table->string('original_name', 255);
            $table->string('detected_mime', 128);
            $table->unsignedBigInteger('byte_size');
            $table->char('checksum', 64);
            // quarantined until a scanner reports clean; never served otherwise.
            $table->string('scan_status', 20);
            $table->string('scanner', 40)->nullable();
            $table->timestampTz('scanned_at', 6)->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('removed_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index(['kpi_actual_id', 'removed_at']);
            $table->index('scan_status');
        });

        Schema::create('actual_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actual_import_batch_id')->constrained('actual_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status', 20);
            $table->json('raw');
            $table->json('errors')->nullable();
            $table->foreignId('period_target_id')->nullable()->constrained('period_targets')->restrictOnDelete();
            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable();
            $table->string('entry_kind', 20)->nullable();
            $table->decimal('actual_value', 18, 4)->nullable();
            $table->string('unit', 30)->nullable();
            $table->string('source_system', 64)->nullable();
            $table->string('source_reference', 191)->nullable();
            $table->text('note')->nullable();
            $table->string('expected_state', 60)->nullable();
            $table->timestampsTz(6);

            $table->unique(['actual_import_batch_id', 'row_number']);
            $table->index(['actual_import_batch_id', 'status']);
        });

        $this->createGuards();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['insert', 'content', 'lifecycle', 'delete', 'final'] as $guard) {
                DB::statement("DROP TRIGGER IF EXISTS kpi_actual_versions_{$guard}_guard ON kpi_actual_versions");
            }

            DB::statement('DROP TRIGGER IF EXISTS kpi_actuals_identity_guard ON kpi_actuals');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_actual_change()');
        }

        Schema::dropIfExists('actual_import_rows');
        Schema::dropIfExists('evidence_files');
        Schema::dropIfExists('kpi_actual_versions');
        Schema::dropIfExists('kpi_actuals');
        Schema::dropIfExists('actual_import_batches');
    }

    private function transitionAllowedSql(): string
    {
        return '(OLD.status = NEW.status'
            ." OR (OLD.status = 'draft' AND NEW.status = 'submitted')"
            ." OR (OLD.status = 'submitted' AND NEW.status IN ('verified', 'rejected'))"
            ." OR (OLD.status = 'rejected' AND NEW.status = 'draft'))";
    }

    private function createGuards(): void
    {
        $content = implode(', ', self::VERSION_CONTENT_COLUMNS);
        $transition = $this->transitionAllowedSql();
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_actual_change()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Actual record is immutable in its current status (%)', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            DB::statement("CREATE TRIGGER kpi_actual_versions_insert_guard BEFORE INSERT ON kpi_actual_versions FOR EACH ROW WHEN (NEW.status <> 'draft') EXECUTE FUNCTION reject_actual_change()");
            DB::statement("CREATE TRIGGER kpi_actual_versions_content_guard BEFORE UPDATE OF {$content} ON kpi_actual_versions FOR EACH ROW WHEN (OLD.status <> 'draft') EXECUTE FUNCTION reject_actual_change()");
            DB::statement("CREATE TRIGGER kpi_actual_versions_lifecycle_guard BEFORE UPDATE OF status ON kpi_actual_versions FOR EACH ROW WHEN (NOT {$transition}) EXECUTE FUNCTION reject_actual_change()");
            DB::statement("CREATE TRIGGER kpi_actual_versions_delete_guard BEFORE DELETE ON kpi_actual_versions FOR EACH ROW WHEN (OLD.status <> 'draft') EXECUTE FUNCTION reject_actual_change()");
            DB::statement("CREATE TRIGGER kpi_actual_versions_final_guard BEFORE UPDATE ON kpi_actual_versions FOR EACH ROW WHEN (OLD.status = 'verified') EXECUTE FUNCTION reject_actual_change()");
            // Target, window, and source identify an actual; they never change.
            DB::statement('CREATE TRIGGER kpi_actuals_identity_guard BEFORE UPDATE OR DELETE ON kpi_actuals FOR EACH ROW EXECUTE FUNCTION reject_actual_change()');
            DB::statement('ALTER TABLE kpi_actuals ADD CONSTRAINT kpi_actuals_window_check CHECK (window_end >= window_start)');
            DB::statement("ALTER TABLE kpi_actual_versions ADD CONSTRAINT kpi_actual_versions_value_check CHECK ((entry_kind = 'value' AND actual_value IS NOT NULL) OR (entry_kind = 'not_applicable' AND actual_value IS NULL))");

            return;
        }

        if ($driver !== 'sqlite') {
            throw new RuntimeException('Step 08 immutability guards require PostgreSQL or SQLite.');
        }

        $abort = fn (string $message): string => "BEGIN SELECT RAISE(ABORT, '{$message}'); END;";

        DB::statement("CREATE TRIGGER kpi_actual_versions_insert_guard BEFORE INSERT ON kpi_actual_versions WHEN NEW.status <> 'draft' {$abort('New actual versions must start as draft')}");
        DB::statement("CREATE TRIGGER kpi_actual_versions_content_guard BEFORE UPDATE OF {$content} ON kpi_actual_versions WHEN OLD.status <> 'draft' {$abort('A submitted or verified actual is immutable')}");
        DB::statement("CREATE TRIGGER kpi_actual_versions_lifecycle_guard BEFORE UPDATE OF status ON kpi_actual_versions WHEN NOT {$transition} {$abort('Invalid actual lifecycle transition')}");
        DB::statement("CREATE TRIGGER kpi_actual_versions_delete_guard BEFORE DELETE ON kpi_actual_versions WHEN OLD.status <> 'draft' {$abort('A submitted or verified actual cannot be deleted')}");
        DB::statement("CREATE TRIGGER kpi_actual_versions_final_guard BEFORE UPDATE ON kpi_actual_versions WHEN OLD.status = 'verified' {$abort('A verified actual is final')}");
        DB::statement("CREATE TRIGGER kpi_actuals_update_guard BEFORE UPDATE ON kpi_actuals {$abort('The identity of an actual is immutable')}");
        DB::statement("CREATE TRIGGER kpi_actuals_delete_guard BEFORE DELETE ON kpi_actuals {$abort('An actual cannot be deleted')}");
    }
};
