<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 06 — KPI dictionary and versioned scorecard templates.
 *
 * Content of a version is editable only while it is a draft. Database
 * triggers (PostgreSQL and SQLite) enforce, also for direct SQL: new versions
 * start as drafts, status changes follow the lifecycle, non-draft content and
 * template items are immutable, and non-draft versions cannot be deleted.
 * Completeness, the 100% weight rule, and non-overlapping effective dates are
 * validated by the application services inside locked transactions.
 */
return new class extends Migration
{
    private const DEFINITION_CONTENT_COLUMNS = [
        'kpi_definition_id', 'version_number', 'name', 'purpose', 'description', 'process_owner', 'data_owner',
        'evaluation_subject', 'unit', 'value_type', 'direction', 'frequency', 'aggregation', 'formula_description',
        'numerator_definition', 'denominator_definition', 'score_strategy', 'score_strategy_version',
        'score_parameters', 'data_source', 'input_method', 'evidence_requirement', 'evidence_description',
        'exception_policy', 'target_rule', 'approval_chain', 'signoff_reference', 'effective_from',
        'change_reason', 'previous_version_id',
    ];

    private const TEMPLATE_CONTENT_COLUMNS = [
        'kpi_template_id', 'version_number', 'notes', 'signoff_reference', 'effective_from', 'change_reason',
        'previous_version_id',
    ];

    public function up(): void
    {
        Schema::create('kpi_definitions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 60)->unique();
            $table->string('origin', 20);
            $table->foreignId('owner_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz(6);

            $table->index(['owner_unit_id', 'origin']);
        });

        Schema::create('kpi_definition_versions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('kpi_definition_id')->constrained('kpi_definitions')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 20);
            $table->string('name', 160);
            $table->text('purpose')->nullable();
            $table->text('description')->nullable();
            $table->string('process_owner', 160)->nullable();
            $table->string('data_owner', 160)->nullable();
            $table->string('evaluation_subject', 20)->nullable();
            $table->string('unit', 30)->nullable();
            $table->string('value_type', 20)->nullable();
            $table->string('direction', 30)->nullable();
            $table->string('frequency', 20)->nullable();
            $table->string('aggregation', 30)->nullable();
            $table->text('formula_description')->nullable();
            $table->text('numerator_definition')->nullable();
            $table->text('denominator_definition')->nullable();
            $table->string('score_strategy', 40)->nullable();
            $table->unsignedSmallInteger('score_strategy_version')->nullable();
            $table->json('score_parameters')->nullable();
            $table->text('data_source')->nullable();
            $table->string('input_method', 30)->nullable();
            $table->string('evidence_requirement', 20)->nullable();
            $table->text('evidence_description')->nullable();
            $table->text('exception_policy')->nullable();
            $table->text('target_rule')->nullable();
            $table->text('approval_chain')->nullable();
            $table->string('signoff_reference', 255)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->text('change_reason')->nullable();
            $table->foreignId('previous_version_id')->nullable()->constrained('kpi_definition_versions')->restrictOnDelete();
            $this->workflowColumns($table);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->unique(['kpi_definition_id', 'version_number']);
            $table->index(['kpi_definition_id', 'status']);
            $table->index('status');
        });

        Schema::create('kpi_templates', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 60)->unique();
            $table->string('name', 160);
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->string('subject', 20);
            $table->string('position_title', 120)->nullable();
            // Lower-cased position title or '' so one scope has one template.
            $table->string('position_key', 120);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz(6);

            $table->unique(['organization_unit_id', 'subject', 'position_key'], 'kpi_templates_scope_unique');
        });

        Schema::create('kpi_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('kpi_template_id')->constrained('kpi_templates')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->string('signoff_reference', 255)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->text('change_reason')->nullable();
            $table->foreignId('previous_version_id')->nullable()->constrained('kpi_template_versions')->restrictOnDelete();
            $this->workflowColumns($table);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->unique(['kpi_template_id', 'version_number']);
            $table->index(['kpi_template_id', 'status']);
        });

        Schema::create('kpi_template_items', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('kpi_template_version_id')->constrained('kpi_template_versions')->restrictOnDelete();
            $table->foreignId('kpi_definition_version_id')->constrained('kpi_definition_versions')->restrictOnDelete();
            $table->string('component', 80)->nullable();
            $table->decimal('weight', 7, 4);
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('is_mandatory')->default(true);
            $table->json('applicability')->nullable();
            $table->timestampsTz(6);

            $table->unique(['kpi_template_version_id', 'kpi_definition_version_id'], 'kpi_template_items_unique_kpi');
            $table->index(['kpi_template_version_id', 'sort_order']);
        });

        $this->createGuards();
    }

    public function down(): void
    {
        $this->dropGuards();

        Schema::dropIfExists('kpi_template_items');
        Schema::dropIfExists('kpi_template_versions');
        Schema::dropIfExists('kpi_templates');
        Schema::dropIfExists('kpi_definition_versions');
        Schema::dropIfExists('kpi_definitions');
    }

    private function workflowColumns(Blueprint $table): void
    {
        $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
        $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
        $table->timestampTz('submitted_at', 6)->nullable();
        $table->foreignId('returned_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
        $table->timestampTz('returned_at', 6)->nullable();
        $table->text('return_reason')->nullable();
        $table->foreignId('published_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
        $table->timestampTz('published_at', 6)->nullable();
        $table->foreignId('retired_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
        $table->timestampTz('retired_at', 6)->nullable();
        $table->text('retire_reason')->nullable();
    }

    private function transitionAllowedSql(string $old, string $new): string
    {
        return "({$old}.status = {$new}.status"
            ." OR ({$old}.status = 'draft' AND {$new}.status = 'review')"
            ." OR ({$old}.status = 'review' AND {$new}.status IN ('draft', 'published'))"
            ." OR ({$old}.status = 'published' AND {$new}.status = 'retired'))";
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

        throw new RuntimeException('Step 06 immutability guards require PostgreSQL or SQLite.');
    }

    private function createPostgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_kpi_governance_change()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'KPI governance record is immutable in its current status (%)', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION guard_kpi_template_item_change()
            RETURNS trigger AS $$
            DECLARE
                parent_status text;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    SELECT status INTO parent_status FROM kpi_template_versions WHERE id = OLD.kpi_template_version_id;
                    IF parent_status IS DISTINCT FROM 'draft' THEN
                        RAISE EXCEPTION 'Items of a non-draft template version are immutable';
                    END IF;
                END IF;
                IF TG_OP IN ('INSERT', 'UPDATE') THEN
                    SELECT status INTO parent_status FROM kpi_template_versions WHERE id = NEW.kpi_template_version_id;
                    IF parent_status IS DISTINCT FROM 'draft' THEN
                        RAISE EXCEPTION 'Items of a non-draft template version are immutable';
                    END IF;
                    RETURN NEW;
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        foreach (['kpi_definition_versions' => self::DEFINITION_CONTENT_COLUMNS, 'kpi_template_versions' => self::TEMPLATE_CONTENT_COLUMNS] as $table => $columns) {
            $list = implode(', ', $columns);
            $transition = $this->transitionAllowedSql('OLD', 'NEW');

            DB::statement("CREATE TRIGGER {$table}_insert_guard BEFORE INSERT ON {$table} FOR EACH ROW WHEN (NEW.status <> 'draft') EXECUTE FUNCTION reject_kpi_governance_change()");
            DB::statement("CREATE TRIGGER {$table}_content_guard BEFORE UPDATE OF {$list} ON {$table} FOR EACH ROW WHEN (OLD.status <> 'draft') EXECUTE FUNCTION reject_kpi_governance_change()");
            // until = from marks a version withdrawn before it ever applied.
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_effective_period_check CHECK (effective_until IS NULL OR effective_from IS NULL OR effective_until >= effective_from)");
            DB::statement("CREATE TRIGGER {$table}_lifecycle_guard BEFORE UPDATE OF status ON {$table} FOR EACH ROW WHEN (NOT {$transition}) EXECUTE FUNCTION reject_kpi_governance_change()");
            DB::statement("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} FOR EACH ROW WHEN (OLD.status <> 'draft') EXECUTE FUNCTION reject_kpi_governance_change()");
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER kpi_template_items_guard
            BEFORE INSERT OR UPDATE OR DELETE ON kpi_template_items
            FOR EACH ROW EXECUTE FUNCTION guard_kpi_template_item_change();
            SQL);
    }

    private function createSqliteGuards(): void
    {
        foreach (['kpi_definition_versions' => self::DEFINITION_CONTENT_COLUMNS, 'kpi_template_versions' => self::TEMPLATE_CONTENT_COLUMNS] as $table => $columns) {
            $list = implode(', ', $columns);
            $transition = $this->transitionAllowedSql('OLD', 'NEW');

            DB::statement("CREATE TRIGGER {$table}_insert_guard BEFORE INSERT ON {$table} WHEN NEW.status <> 'draft' BEGIN SELECT RAISE(ABORT, 'New KPI governance versions must start as draft'); END;");
            DB::statement("CREATE TRIGGER {$table}_content_guard BEFORE UPDATE OF {$list} ON {$table} WHEN OLD.status <> 'draft' BEGIN SELECT RAISE(ABORT, 'KPI governance record is immutable in its current status'); END;");
            DB::statement("CREATE TRIGGER {$table}_lifecycle_guard BEFORE UPDATE OF status ON {$table} WHEN NOT {$transition} BEGIN SELECT RAISE(ABORT, 'Invalid KPI governance lifecycle transition'); END;");
            DB::statement("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} WHEN OLD.status <> 'draft' BEGIN SELECT RAISE(ABORT, 'KPI governance record is immutable in its current status'); END;");
        }

        $parentIsNotDraft = fn (string $row): string => "(SELECT status FROM kpi_template_versions WHERE id = {$row}.kpi_template_version_id) <> 'draft'";

        DB::statement("CREATE TRIGGER kpi_template_items_insert_guard BEFORE INSERT ON kpi_template_items WHEN {$parentIsNotDraft('NEW')} BEGIN SELECT RAISE(ABORT, 'Items of a non-draft template version are immutable'); END;");
        DB::statement("CREATE TRIGGER kpi_template_items_update_guard BEFORE UPDATE ON kpi_template_items WHEN {$parentIsNotDraft('OLD')} OR {$parentIsNotDraft('NEW')} BEGIN SELECT RAISE(ABORT, 'Items of a non-draft template version are immutable'); END;");
        DB::statement("CREATE TRIGGER kpi_template_items_delete_guard BEFORE DELETE ON kpi_template_items WHEN {$parentIsNotDraft('OLD')} BEGIN SELECT RAISE(ABORT, 'Items of a non-draft template version are immutable'); END;");
    }

    private function dropGuards(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // SQLite triggers are dropped with their tables.
        }

        DB::unprepared('DROP TRIGGER IF EXISTS kpi_template_items_guard ON kpi_template_items');

        foreach (['kpi_definition_versions', 'kpi_template_versions'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_insert_guard ON {$table}");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_content_guard ON {$table}");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_lifecycle_guard ON {$table}");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_delete_guard ON {$table}");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS guard_kpi_template_item_change()');
        DB::unprepared('DROP FUNCTION IF EXISTS reject_kpi_governance_change()');
    }
};
