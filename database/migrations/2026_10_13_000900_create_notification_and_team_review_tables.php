<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 11 — notifications, daily team review, corrective actions, and
 * coaching notes.
 *
 * - user_notifications: one row per recipient and deduplication key, so a
 *   reminder or event that is processed twice still notifies once.
 * - notification_deliveries: one row per notification and channel with the
 *   attempt count and outcome. No message content is stored here.
 * - team_review_issues / corrective_actions: the problem a team recorded
 *   and what is done about it, with an owner and a due date. An action is
 *   never deleted and moves only along its lifecycle; a closed action is
 *   final. corrective_action_events is its append-only history.
 * - coaching_notes: restricted, append-only notes about one employee.
 *
 * Guards are database triggers (PostgreSQL and SQLite).
 */
return new class extends Migration
{
    private const APPEND_ONLY = ['corrective_action_events', 'coaching_notes'];

    private const ACTION_TRANSITION = '(OLD.status = NEW.status'
        ." OR (OLD.status = 'open' AND NEW.status = 'in_progress')"
        ." OR (OLD.status = 'in_progress' AND NEW.status IN ('waiting', 'verified'))"
        ." OR (OLD.status = 'waiting' AND NEW.status = 'in_progress')"
        ." OR (OLD.status = 'verified' AND NEW.status = 'closed'))";

    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('title', 200);
            $table->text('body');
            $table->string('route_name', 100)->nullable();
            $table->json('route_parameters')->nullable();
            $table->string('deduplication_key', 191);
            $table->timestampTz('read_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['user_id', 'deduplication_key'], 'user_notifications_dedup_unique');
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_notification_id')->constrained('user_notifications')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('status', 20);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 60)->nullable();
            $table->timestampTz('last_attempt_at', 6)->nullable();
            $table->timestampTz('delivered_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['user_notification_id', 'channel'], 'notification_deliveries_channel_unique');
            $table->index(['channel', 'status']);
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('channel', 20);
            $table->boolean('is_enabled');
            $table->timestampsTz(6);

            $table->unique(['user_id', 'category', 'channel'], 'notification_preferences_unique');
        });

        Schema::create('team_review_issues', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->date('review_date');
            $table->text('problem');
            $table->string('cause_code', 60)->nullable();
            $table->text('cause_note')->nullable();
            $table->string('context_type', 30)->nullable();
            $table->string('context_reference', 120)->nullable();
            $table->foreignId('reported_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz(6);

            $table->index(['organization_unit_id', 'review_date']);
        });

        Schema::create('team_review_issue_kpis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_review_issue_id')->constrained('team_review_issues')->restrictOnDelete();
            $table->foreignId('kpi_definition_id')->constrained('kpi_definitions')->restrictOnDelete();
            $table->timestampsTz(6);

            $table->unique(['team_review_issue_id', 'kpi_definition_id'], 'team_review_issue_kpis_unique');
        });

        Schema::create('corrective_actions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('team_review_issue_id')->constrained('team_review_issues')->restrictOnDelete();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->text('action');
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->date('due_on');
            $table->string('priority', 10);
            $table->string('status', 20);
            $table->text('waiting_reason')->nullable();
            $table->text('completion_note')->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at', 6)->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at', 6)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->index(['organization_unit_id', 'status', 'due_on']);
            $table->index(['owner_user_id', 'status']);
        });

        Schema::create('corrective_action_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corrective_action_id')->constrained('corrective_actions')->restrictOnDelete();
            $table->string('event', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('note')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at', 6);
            $table->timestampsTz(6);

            $table->index('corrective_action_id');
        });

        Schema::create('coaching_notes', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->date('noted_on');
            $table->text('note');
            $table->timestampsTz(6);

            $table->index(['employee_id', 'noted_on']);
            $table->index(['organization_unit_id', 'noted_on']);
        });

        $this->createGuards();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['corrective_actions', 'corrective_action_events', 'coaching_notes'] as $table) {
                foreach (['insert', 'identity', 'lifecycle', 'final', 'delete', 'immutable'] as $guard) {
                    DB::statement("DROP TRIGGER IF EXISTS {$table}_{$guard}_guard ON {$table}");
                }
            }

            DB::unprepared('DROP FUNCTION IF EXISTS reject_team_review_change()');
        }

        Schema::dropIfExists('coaching_notes');
        Schema::dropIfExists('corrective_action_events');
        Schema::dropIfExists('corrective_actions');
        Schema::dropIfExists('team_review_issue_kpis');
        Schema::dropIfExists('team_review_issues');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('user_notifications');
    }

    private function createGuards(): void
    {
        $driver = DB::getDriverName();
        $transition = self::ACTION_TRANSITION;

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_team_review_change()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Team review record cannot be changed this way (%)', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            $always = 'FOR EACH ROW EXECUTE FUNCTION reject_team_review_change()';
            $when = static fn (string $condition): string => "FOR EACH ROW WHEN ({$condition}) EXECUTE FUNCTION reject_team_review_change()";

            DB::statement("CREATE TRIGGER corrective_actions_insert_guard BEFORE INSERT ON corrective_actions {$when("NEW.status <> 'open'")}");
            DB::statement("CREATE TRIGGER corrective_actions_identity_guard BEFORE UPDATE OF team_review_issue_id, organization_unit_id, public_id, created_by_user_id ON corrective_actions {$always}");
            DB::statement("CREATE TRIGGER corrective_actions_lifecycle_guard BEFORE UPDATE OF status ON corrective_actions {$when("NOT {$transition}")}");
            DB::statement("CREATE TRIGGER corrective_actions_final_guard BEFORE UPDATE ON corrective_actions {$when("OLD.status = 'closed'")}");
            DB::statement("CREATE TRIGGER corrective_actions_delete_guard BEFORE DELETE ON corrective_actions {$always}");

            foreach (self::APPEND_ONLY as $table) {
                DB::statement("CREATE TRIGGER {$table}_immutable_guard BEFORE UPDATE OR DELETE ON {$table} {$always}");
            }

            DB::statement("ALTER TABLE corrective_actions ADD CONSTRAINT corrective_actions_status_check CHECK (status IN ('open', 'in_progress', 'waiting', 'verified', 'closed'))");
            DB::statement("ALTER TABLE corrective_actions ADD CONSTRAINT corrective_actions_priority_check CHECK (priority IN ('low', 'medium', 'high'))");
            DB::statement("ALTER TABLE corrective_actions ADD CONSTRAINT corrective_actions_verified_check CHECK (status NOT IN ('verified', 'closed') OR (reviewer_user_id IS NOT NULL AND reviewer_user_id <> owner_user_id AND completed_at IS NOT NULL))");
            DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('pending', 'sent', 'failed', 'skipped'))");

            return;
        }

        if ($driver !== 'sqlite') {
            throw new RuntimeException('Step 11 guards require PostgreSQL or SQLite.');
        }

        $abort = static fn (string $message): string => "BEGIN SELECT RAISE(ABORT, '{$message}'); END;";

        DB::statement("CREATE TRIGGER corrective_actions_insert_guard BEFORE INSERT ON corrective_actions WHEN NEW.status <> 'open' {$abort('A corrective action starts as open')}");
        DB::statement("CREATE TRIGGER corrective_actions_identity_guard BEFORE UPDATE OF team_review_issue_id, organization_unit_id, public_id, created_by_user_id ON corrective_actions {$abort('The identity of a corrective action is immutable')}");
        DB::statement("CREATE TRIGGER corrective_actions_lifecycle_guard BEFORE UPDATE OF status ON corrective_actions WHEN NOT {$transition} {$abort('Invalid corrective action transition')}");
        DB::statement("CREATE TRIGGER corrective_actions_final_guard BEFORE UPDATE ON corrective_actions WHEN OLD.status = 'closed' {$abort('A closed corrective action is final')}");
        DB::statement("CREATE TRIGGER corrective_actions_delete_guard BEFORE DELETE ON corrective_actions {$abort('A corrective action cannot be deleted')}");

        foreach (self::APPEND_ONLY as $table) {
            DB::statement("CREATE TRIGGER {$table}_update_guard BEFORE UPDATE ON {$table} {$abort('This history is append-only')}");
            DB::statement("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} {$abort('This history is append-only')}");
        }
    }
};
