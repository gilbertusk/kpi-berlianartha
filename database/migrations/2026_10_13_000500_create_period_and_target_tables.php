<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 07 — assessment periods, assignment snapshots, and KPI targets.
 *
 * Database triggers (PostgreSQL and SQLite) enforce, also for direct SQL:
 * a period follows its lifecycle and its identity is frozen once opened;
 * snapshot rows (assignments, approvers, target slots) never change; a
 * target version is editable only as a draft and follows
 * draft → submitted → approved. Overlap, completeness, cut-off, and the
 * two-person rule are validated by the application services inside locked
 * transactions.
 */
return new class extends Migration
{
    private const PERIOD_IDENTITY_COLUMNS = [
        'code', 'period_type', 'starts_on', 'ends_on', 'timezone', 'organization_unit_id', 'assignment_reference_date',
    ];

    private const TARGET_VERSION_CONTENT_COLUMNS = [
        'period_target_id', 'version_number', 'target_value', 'unit', 'source', 'source_note',
        'target_import_batch_id', 'change_reason', 'after_cutoff', 'previous_version_id',
    ];

    private const SNAPSHOT_TABLES = ['period_assignments', 'period_assignment_approvers', 'period_targets'];

    public function up(): void
    {
        Schema::create('assessment_periods', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 60)->unique();
            $table->string('name', 160);
            $table->string('period_type', 20);
            // Both dates are inclusive business dates in `timezone`.
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('timezone', 64);
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->date('assignment_reference_date');
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at', 6)->nullable();
            $table->foreignId('progress_started_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('progress_started_at', 6)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->unique(['period_type', 'organization_unit_id', 'starts_on', 'ends_on'], 'assessment_periods_identity_unique');
            $table->index(['period_type', 'starts_on']);
            $table->index('status');
        });

        Schema::create('assessment_period_deadlines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assessment_period_id')->constrained('assessment_periods')->cascadeOnDelete();
            $table->string('stage', 30);
            // Inclusive: the stage closes at the end of this business date.
            $table->date('due_on');
            $table->timestampsTz(6);

            $table->unique(['assessment_period_id', 'stage']);
            $table->index(['stage', 'due_on']);
        });

        Schema::create('period_assignments', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('assessment_period_id')->constrained('assessment_periods')->restrictOnDelete();
            $table->string('subject_type', 20);
            $table->string('subject_key', 40);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->foreignId('source_assignment_id')->nullable()->constrained('employee_assignments')->restrictOnDelete();
            $table->string('subject_code', 60);
            $table->string('subject_name', 160);
            $table->string('position_title', 120)->nullable();
            $table->string('unit_code', 40);
            $table->string('unit_name', 120);
            $table->string('unit_type', 20);
            $table->json('unit_path');
            $table->foreignId('kpi_template_version_id')->nullable()->constrained('kpi_template_versions')->restrictOnDelete();
            $table->string('template_code', 60)->nullable();
            $table->unsignedInteger('template_version_number')->nullable();
            $table->string('coverage', 30);
            $table->json('approver_gaps')->nullable();
            $table->timestampsTz(6);

            $table->unique(['assessment_period_id', 'subject_key']);
            $table->index(['assessment_period_id', 'organization_unit_id']);
            $table->index(['employee_id', 'assessment_period_id']);
        });

        Schema::create('period_assignment_approvers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_assignment_id')->constrained('period_assignments')->restrictOnDelete();
            $table->string('stage', 30);
            $table->string('role', 30);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('user_name', 255);
            $table->foreignId('scope_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->string('scope_unit_code', 40);
            $table->char('role_assignment_public_id', 26);
            $table->timestampsTz(6);

            $table->unique(['period_assignment_id', 'stage', 'user_id'], 'period_assignment_approvers_unique');
            $table->index(['user_id', 'stage']);
        });

        Schema::create('target_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('assessment_period_id')->constrained('assessment_periods')->restrictOnDelete();
            $table->string('status', 20);
            $table->string('file_name', 255);
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

        Schema::create('period_targets', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('assessment_period_id')->constrained('assessment_periods')->restrictOnDelete();
            $table->foreignId('kpi_definition_version_id')->constrained('kpi_definition_versions')->restrictOnDelete();
            $table->string('kpi_code', 60);
            $table->string('kpi_name', 160);
            $table->string('subject_type', 20);
            $table->string('subject_key', 40);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->string('subject_code', 60);
            $table->string('subject_name', 160);
            $table->string('unit_code', 40);
            $table->string('unit_name', 120);
            $table->boolean('is_required');
            $table->timestampsTz(6);

            $table->unique(['assessment_period_id', 'kpi_definition_version_id', 'subject_key'], 'period_targets_slot_unique');
            $table->index(['assessment_period_id', 'organization_unit_id']);
            $table->index(['assessment_period_id', 'kpi_code']);
        });

        Schema::create('period_target_versions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('period_target_id')->constrained('period_targets')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 20);
            $table->decimal('target_value', 18, 4);
            $table->string('unit', 30);
            $table->string('source', 20);
            $table->text('source_note')->nullable();
            $table->foreignId('target_import_batch_id')->nullable()->constrained('target_import_batches')->restrictOnDelete();
            $table->text('change_reason')->nullable();
            $table->boolean('after_cutoff')->default(false);
            $table->foreignId('previous_version_id')->nullable()->constrained('period_target_versions')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            // Last account that wrote the value; it may not approve it.
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at', 6)->nullable();
            $table->foreignId('returned_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('returned_at', 6)->nullable();
            $table->text('return_reason')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at', 6)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->unique(['period_target_id', 'version_number']);
            $table->index(['period_target_id', 'status']);
        });

        Schema::create('target_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_import_batch_id')->constrained('target_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status', 20);
            $table->json('raw');
            $table->json('errors')->nullable();
            $table->foreignId('period_target_id')->nullable()->constrained('period_targets')->restrictOnDelete();
            $table->decimal('target_value', 18, 4)->nullable();
            $table->string('unit', 30)->nullable();
            $table->text('source_note')->nullable();
            // State of the target when the row was validated; a commit is
            // refused when it no longer matches.
            $table->string('expected_state', 60)->nullable();
            $table->timestampsTz(6);

            $table->unique(['target_import_batch_id', 'row_number']);
            $table->index(['target_import_batch_id', 'status']);
        });

        $this->createGuards();
    }

    public function down(): void
    {
        $this->dropGuards();

        Schema::dropIfExists('target_import_rows');
        Schema::dropIfExists('period_target_versions');
        Schema::dropIfExists('period_targets');
        Schema::dropIfExists('target_import_batches');
        Schema::dropIfExists('period_assignment_approvers');
        Schema::dropIfExists('period_assignments');
        Schema::dropIfExists('assessment_period_deadlines');
        Schema::dropIfExists('assessment_periods');
    }

    private function periodTransitionAllowedSql(string $old, string $new): string
    {
        return "({$old}.status = {$new}.status"
            ." OR ({$old}.status = 'draft' AND {$new}.status = 'open')"
            ." OR ({$old}.status = 'open' AND {$new}.status = 'in_progress')"
            ." OR ({$old}.status = 'in_progress' AND {$new}.status = 'review')"
            ." OR ({$old}.status = 'review' AND {$new}.status = 'finalized')"
            ." OR ({$old}.status = 'finalized' AND {$new}.status = 'locked'))";
    }

    private function targetTransitionAllowedSql(string $old, string $new): string
    {
        return "({$old}.status = {$new}.status"
            ." OR ({$old}.status = 'draft' AND {$new}.status = 'submitted')"
            ." OR ({$old}.status = 'submitted' AND {$new}.status IN ('draft', 'approved')))";
    }

    private function createGuards(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $this->createPostgresGuards();

            return;
        }

        if ($driver === 'sqlite') {
            $this->createSqliteGuards();

            return;
        }

        throw new RuntimeException('Step 07 immutability guards require PostgreSQL or SQLite.');
    }

    private function createPostgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_period_target_change()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Period or target record is immutable in its current status (%)', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        $identity = implode(', ', self::PERIOD_IDENTITY_COLUMNS);
        $periodTransition = $this->periodTransitionAllowedSql('OLD', 'NEW');

        DB::statement("CREATE TRIGGER assessment_periods_insert_guard BEFORE INSERT ON assessment_periods FOR EACH ROW WHEN (NEW.status <> 'draft') EXECUTE FUNCTION reject_period_target_change()");
        DB::statement("CREATE TRIGGER assessment_periods_identity_guard BEFORE UPDATE OF {$identity} ON assessment_periods FOR EACH ROW WHEN (OLD.status <> 'draft') EXECUTE FUNCTION reject_period_target_change()");
        DB::statement("CREATE TRIGGER assessment_periods_lifecycle_guard BEFORE UPDATE OF status ON assessment_periods FOR EACH ROW WHEN (NOT {$periodTransition}) EXECUTE FUNCTION reject_period_target_change()");
        DB::statement("CREATE TRIGGER assessment_periods_delete_guard BEFORE DELETE ON assessment_periods FOR EACH ROW WHEN (OLD.status <> 'draft') EXECUTE FUNCTION reject_period_target_change()");
        DB::statement('ALTER TABLE assessment_periods ADD CONSTRAINT assessment_periods_range_check CHECK (ends_on >= starts_on AND assignment_reference_date >= starts_on AND assignment_reference_date <= ends_on)');

        foreach (self::SNAPSHOT_TABLES as $table) {
            DB::statement("CREATE TRIGGER {$table}_snapshot_guard BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_period_target_change()");
        }

        $content = implode(', ', self::TARGET_VERSION_CONTENT_COLUMNS);
        $targetTransition = $this->targetTransitionAllowedSql('OLD', 'NEW');

        DB::statement("CREATE TRIGGER period_target_versions_insert_guard BEFORE INSERT ON period_target_versions FOR EACH ROW WHEN (NEW.status <> 'draft') EXECUTE FUNCTION reject_period_target_change()");
        DB::statement("CREATE TRIGGER period_target_versions_content_guard BEFORE UPDATE OF {$content} ON period_target_versions FOR EACH ROW WHEN (OLD.status <> 'draft') EXECUTE FUNCTION reject_period_target_change()");
        DB::statement("CREATE TRIGGER period_target_versions_lifecycle_guard BEFORE UPDATE OF status ON period_target_versions FOR EACH ROW WHEN (NOT {$targetTransition}) EXECUTE FUNCTION reject_period_target_change()");
        DB::statement("CREATE TRIGGER period_target_versions_delete_guard BEFORE DELETE ON period_target_versions FOR EACH ROW WHEN (OLD.status <> 'draft') EXECUTE FUNCTION reject_period_target_change()");
        // Approved is terminal: who submitted and approved it is evidence too.
        DB::statement("CREATE TRIGGER period_target_versions_final_guard BEFORE UPDATE ON period_target_versions FOR EACH ROW WHEN (OLD.status = 'approved') EXECUTE FUNCTION reject_period_target_change()");
    }

    private function createSqliteGuards(): void
    {
        $abort = fn (string $message): string => "BEGIN SELECT RAISE(ABORT, '{$message}'); END;";
        $identity = implode(', ', self::PERIOD_IDENTITY_COLUMNS);
        $periodTransition = $this->periodTransitionAllowedSql('OLD', 'NEW');

        DB::statement("CREATE TRIGGER assessment_periods_insert_guard BEFORE INSERT ON assessment_periods WHEN NEW.status <> 'draft' {$abort('New assessment periods must start as draft')}");
        DB::statement("CREATE TRIGGER assessment_periods_identity_guard BEFORE UPDATE OF {$identity} ON assessment_periods WHEN OLD.status <> 'draft' {$abort('An opened assessment period keeps its identity')}");
        DB::statement("CREATE TRIGGER assessment_periods_lifecycle_guard BEFORE UPDATE OF status ON assessment_periods WHEN NOT {$periodTransition} {$abort('Invalid assessment period lifecycle transition')}");
        DB::statement("CREATE TRIGGER assessment_periods_delete_guard BEFORE DELETE ON assessment_periods WHEN OLD.status <> 'draft' {$abort('An opened assessment period cannot be deleted')}");

        foreach (self::SNAPSHOT_TABLES as $table) {
            DB::statement("CREATE TRIGGER {$table}_update_guard BEFORE UPDATE ON {$table} {$abort('Period snapshot rows are immutable')}");
            DB::statement("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} {$abort('Period snapshot rows are immutable')}");
        }

        $content = implode(', ', self::TARGET_VERSION_CONTENT_COLUMNS);
        $targetTransition = $this->targetTransitionAllowedSql('OLD', 'NEW');

        DB::statement("CREATE TRIGGER period_target_versions_insert_guard BEFORE INSERT ON period_target_versions WHEN NEW.status <> 'draft' {$abort('New target versions must start as draft')}");
        DB::statement("CREATE TRIGGER period_target_versions_content_guard BEFORE UPDATE OF {$content} ON period_target_versions WHEN OLD.status <> 'draft' {$abort('A submitted or approved target version is immutable')}");
        DB::statement("CREATE TRIGGER period_target_versions_lifecycle_guard BEFORE UPDATE OF status ON period_target_versions WHEN NOT {$targetTransition} {$abort('Invalid target lifecycle transition')}");
        DB::statement("CREATE TRIGGER period_target_versions_delete_guard BEFORE DELETE ON period_target_versions WHEN OLD.status <> 'draft' {$abort('A submitted or approved target version cannot be deleted')}");
        DB::statement("CREATE TRIGGER period_target_versions_final_guard BEFORE UPDATE ON period_target_versions WHEN OLD.status = 'approved' {$abort('An approved target version is final')}");
    }

    private function dropGuards(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // SQLite triggers are dropped with their tables.
        }

        foreach (['insert', 'content', 'lifecycle', 'delete', 'final'] as $guard) {
            DB::statement("DROP TRIGGER IF EXISTS period_target_versions_{$guard}_guard ON period_target_versions");
        }

        foreach (self::SNAPSHOT_TABLES as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_snapshot_guard ON {$table}");
        }

        foreach (['insert', 'identity', 'lifecycle', 'delete'] as $guard) {
            DB::statement("DROP TRIGGER IF EXISTS assessment_periods_{$guard}_guard ON assessment_periods");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS reject_period_target_change()');
    }
};
