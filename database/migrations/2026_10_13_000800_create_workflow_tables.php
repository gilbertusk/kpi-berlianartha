<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 10 — approval workflow, calibration, stage bypass, and corrections.
 *
 * - scorecard_reviews: one row per scorecard subject of a period in review.
 *   Its identity never changes and it is never deleted; the state moves only
 *   along the workflow.
 * - scorecard_review_decisions and scorecard_calibrations: append-only logs.
 *   A calibration keeps the calculated score next to the calibrated one, and
 *   the calculation snapshot itself stays untouched.
 * - approval_stage_bypasses: a signed, dated permission to skip a stage that
 *   has no approver for one team. Its content is fixed; it can only be revoked.
 * - correction_requests: a request to replace a verified actual. Once decided
 *   it is final.
 *
 * All guards are database triggers (PostgreSQL and SQLite), so they also
 * hold for direct SQL.
 */
return new class extends Migration
{
    private const APPEND_ONLY = ['scorecard_review_decisions', 'scorecard_calibrations'];

    private const REVIEW_TRANSITION = '(OLD.state = NEW.state'
        ." OR (OLD.state = 'in_review' AND NEW.state IN ('reviewed', 'returned'))"
        ." OR (OLD.state = 'reviewed' AND NEW.state IN ('calibrated', 'returned', 'in_review'))"
        ." OR (OLD.state = 'calibrated' AND NEW.state IN ('final_approved', 'returned', 'in_review'))"
        ." OR (OLD.state = 'returned' AND NEW.state = 'in_review')"
        ." OR (OLD.state = 'final_approved' AND NEW.state = 'in_review'))";

    private const BYPASS_CONTENT = 'organization_unit_id, stage, signoff_reference, reason, effective_from, effective_until, created_by_user_id';

    private const CORRECTION_CONTENT = 'assessment_period_id, kpi_actual_id, current_version_id, proposed_entry_kind, proposed_value, proposed_note, reason, evidence_reference, requested_by_user_id, requested_at';

    public function up(): void
    {
        Schema::create('scorecard_reviews', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('assessment_period_id')->constrained('assessment_periods')->restrictOnDelete();
            $table->foreignId('period_assignment_id')->unique()->constrained('period_assignments')->restrictOnDelete();
            $table->foreignId('scorecard_calculation_id')->constrained('scorecard_calculations')->restrictOnDelete();
            $table->string('state', 20);
            $table->unsignedInteger('round')->default(1);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->index(['assessment_period_id', 'state']);
        });

        Schema::create('approval_stage_bypasses', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->string('stage', 20);
            $table->string('signoff_reference', 255);
            $table->text('reason');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('revoke_reason')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->index(['organization_unit_id', 'stage']);
        });

        Schema::create('scorecard_review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('scorecard_review_id')->constrained('scorecard_reviews')->restrictOnDelete();
            $table->unsignedInteger('round');
            $table->string('stage', 20);
            $table->string('outcome', 20);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 40);
            $table->foreignId('acting_for_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('grant_source', 20)->nullable();
            $table->string('grant_reference', 26)->nullable();
            $table->foreignId('approval_stage_bypass_id')->nullable()->constrained('approval_stage_bypasses')->restrictOnDelete();
            $table->foreignId('scorecard_calculation_id')->constrained('scorecard_calculations')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->timestampTz('decided_at', 6);
            $table->timestampsTz(6);

            $table->index(['scorecard_review_id', 'round']);
            $table->index('actor_user_id');
        });

        Schema::create('scorecard_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('scorecard_review_id')->constrained('scorecard_reviews')->restrictOnDelete();
            $table->unsignedInteger('round');
            $table->foreignId('scorecard_calculation_id')->constrained('scorecard_calculations')->restrictOnDelete();
            $table->decimal('original_final_score', 18, 4);
            $table->string('original_rating_key', 40);
            $table->string('original_rating_label', 80);
            $table->decimal('calibrated_final_score', 18, 4);
            $table->string('calibrated_rating_key', 40);
            $table->string('calibrated_rating_label', 80);
            $table->boolean('is_adjusted');
            $table->text('reason')->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->foreignId('calibrated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('calibrated_at', 6);
            $table->timestampsTz(6);

            $table->unique(['scorecard_review_id', 'round'], 'scorecard_calibrations_round_unique');
        });

        Schema::create('correction_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('assessment_period_id')->constrained('assessment_periods')->restrictOnDelete();
            $table->foreignId('kpi_actual_id')->constrained('kpi_actuals')->restrictOnDelete();
            $table->foreignId('current_version_id')->constrained('kpi_actual_versions')->restrictOnDelete();
            $table->string('proposed_entry_kind', 20);
            $table->decimal('proposed_value', 18, 4)->nullable();
            $table->string('proposed_note', 1000)->nullable();
            $table->text('reason');
            $table->string('evidence_reference', 255)->nullable();
            $table->string('status', 20);
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at', 6);
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at', 6)->nullable();
            $table->text('decision_comment')->nullable();
            $table->foreignId('new_version_id')->nullable()->constrained('kpi_actual_versions')->restrictOnDelete();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->index(['assessment_period_id', 'status']);
        });

        // One decision per stage per round; corrections can repeat in a round.
        DB::statement("CREATE UNIQUE INDEX scorecard_review_decisions_stage_unique ON scorecard_review_decisions (scorecard_review_id, round, stage) WHERE stage <> 'correction'");
        // At most one open request per actual.
        DB::statement("CREATE UNIQUE INDEX correction_requests_pending_unique ON correction_requests (kpi_actual_id) WHERE status = 'pending'");

        $this->createGuards();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['scorecard_reviews', 'approval_stage_bypasses', 'scorecard_review_decisions', 'scorecard_calibrations', 'correction_requests'] as $table) {
                foreach (['insert', 'identity', 'lifecycle', 'delete', 'immutable', 'content', 'final'] as $guard) {
                    DB::statement("DROP TRIGGER IF EXISTS {$table}_{$guard}_guard ON {$table}");
                }
            }

            DB::unprepared('DROP FUNCTION IF EXISTS reject_workflow_change()');
        }

        Schema::dropIfExists('correction_requests');
        Schema::dropIfExists('scorecard_calibrations');
        Schema::dropIfExists('scorecard_review_decisions');
        Schema::dropIfExists('approval_stage_bypasses');
        Schema::dropIfExists('scorecard_reviews');
    }

    private function createGuards(): void
    {
        $driver = DB::getDriverName();
        $transition = self::REVIEW_TRANSITION;
        $bypass = self::BYPASS_CONTENT;
        $correction = self::CORRECTION_CONTENT;

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_workflow_change()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Workflow record cannot be changed this way (%)', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            $reject = 'FOR EACH ROW %s EXECUTE FUNCTION reject_workflow_change()';
            $when = static fn (string $condition): string => sprintf($reject, "WHEN ({$condition})");

            DB::statement("CREATE TRIGGER scorecard_reviews_insert_guard BEFORE INSERT ON scorecard_reviews {$when("NEW.state <> 'in_review' OR NEW.round <> 1")}");
            DB::statement('CREATE TRIGGER scorecard_reviews_identity_guard BEFORE UPDATE OF assessment_period_id, period_assignment_id, public_id ON scorecard_reviews '.sprintf($reject, ''));
            DB::statement("CREATE TRIGGER scorecard_reviews_lifecycle_guard BEFORE UPDATE OF state, round ON scorecard_reviews {$when("NOT {$transition} OR NEW.round < OLD.round")}");
            DB::statement('CREATE TRIGGER scorecard_reviews_delete_guard BEFORE DELETE ON scorecard_reviews '.sprintf($reject, ''));

            foreach (self::APPEND_ONLY as $table) {
                DB::statement("CREATE TRIGGER {$table}_immutable_guard BEFORE UPDATE OR DELETE ON {$table} ".sprintf($reject, ''));
            }

            DB::statement("CREATE TRIGGER approval_stage_bypasses_content_guard BEFORE UPDATE OF {$bypass} ON approval_stage_bypasses ".sprintf($reject, ''));
            DB::statement("CREATE TRIGGER approval_stage_bypasses_final_guard BEFORE UPDATE ON approval_stage_bypasses {$when('OLD.revoked_at IS NOT NULL')}");
            DB::statement('CREATE TRIGGER approval_stage_bypasses_delete_guard BEFORE DELETE ON approval_stage_bypasses '.sprintf($reject, ''));

            DB::statement("CREATE TRIGGER correction_requests_insert_guard BEFORE INSERT ON correction_requests {$when("NEW.status <> 'pending'")}");
            DB::statement("CREATE TRIGGER correction_requests_content_guard BEFORE UPDATE OF {$correction} ON correction_requests ".sprintf($reject, ''));
            DB::statement("CREATE TRIGGER correction_requests_final_guard BEFORE UPDATE ON correction_requests {$when("OLD.status <> 'pending'")}");
            DB::statement('CREATE TRIGGER correction_requests_delete_guard BEFORE DELETE ON correction_requests '.sprintf($reject, ''));

            DB::statement("ALTER TABLE scorecard_reviews ADD CONSTRAINT scorecard_reviews_state_check CHECK (state IN ('in_review', 'reviewed', 'calibrated', 'final_approved', 'returned'))");
            DB::statement("ALTER TABLE scorecard_review_decisions ADD CONSTRAINT scorecard_review_decisions_stage_check CHECK (stage IN ('review', 'calibration', 'final_approval', 'resubmission', 'correction'))");
            DB::statement("ALTER TABLE scorecard_review_decisions ADD CONSTRAINT scorecard_review_decisions_outcome_check CHECK (outcome IN ('approved', 'returned', 'bypassed', 'resubmitted', 'recalculated'))");
            DB::statement("ALTER TABLE scorecard_review_decisions ADD CONSTRAINT scorecard_review_decisions_bypass_check CHECK ((outcome = 'bypassed') = (approval_stage_bypass_id IS NOT NULL))");
            DB::statement('ALTER TABLE scorecard_calibrations ADD CONSTRAINT scorecard_calibrations_reason_check CHECK (NOT is_adjusted OR reason IS NOT NULL)');
            DB::statement("ALTER TABLE approval_stage_bypasses ADD CONSTRAINT approval_stage_bypasses_stage_check CHECK (stage = 'review')");
            DB::statement('ALTER TABLE approval_stage_bypasses ADD CONSTRAINT approval_stage_bypasses_period_check CHECK (effective_until IS NULL OR effective_until > effective_from)');
            DB::statement("ALTER TABLE correction_requests ADD CONSTRAINT correction_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))");
            DB::statement("ALTER TABLE correction_requests ADD CONSTRAINT correction_requests_decision_check CHECK (status = 'pending' OR (decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL))");
            DB::statement("ALTER TABLE correction_requests ADD CONSTRAINT correction_requests_two_person_check CHECK (status <> 'approved' OR (decided_by_user_id <> requested_by_user_id AND new_version_id IS NOT NULL))");

            return;
        }

        if ($driver !== 'sqlite') {
            throw new RuntimeException('Step 10 workflow guards require PostgreSQL or SQLite.');
        }

        $abort = static fn (string $message): string => "BEGIN SELECT RAISE(ABORT, '{$message}'); END;";

        DB::statement("CREATE TRIGGER scorecard_reviews_insert_guard BEFORE INSERT ON scorecard_reviews WHEN NEW.state <> 'in_review' OR NEW.round <> 1 {$abort('A scorecard review starts in review, round 1')}");
        DB::statement("CREATE TRIGGER scorecard_reviews_identity_guard BEFORE UPDATE OF assessment_period_id, period_assignment_id, public_id ON scorecard_reviews {$abort('The identity of a scorecard review is immutable')}");
        DB::statement("CREATE TRIGGER scorecard_reviews_lifecycle_guard BEFORE UPDATE OF state, round ON scorecard_reviews WHEN NOT {$transition} OR NEW.round < OLD.round {$abort('Invalid scorecard review transition')}");
        DB::statement("CREATE TRIGGER scorecard_reviews_delete_guard BEFORE DELETE ON scorecard_reviews {$abort('A scorecard review cannot be deleted')}");

        foreach (self::APPEND_ONLY as $table) {
            DB::statement("CREATE TRIGGER {$table}_update_guard BEFORE UPDATE ON {$table} {$abort('Workflow history is append-only')}");
            DB::statement("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} {$abort('Workflow history is append-only')}");
        }

        DB::statement("CREATE TRIGGER approval_stage_bypasses_content_guard BEFORE UPDATE OF {$bypass} ON approval_stage_bypasses {$abort('The content of a stage bypass is immutable')}");
        DB::statement("CREATE TRIGGER approval_stage_bypasses_final_guard BEFORE UPDATE ON approval_stage_bypasses WHEN OLD.revoked_at IS NOT NULL {$abort('A revoked stage bypass is final')}");
        DB::statement("CREATE TRIGGER approval_stage_bypasses_delete_guard BEFORE DELETE ON approval_stage_bypasses {$abort('A stage bypass cannot be deleted')}");

        DB::statement("CREATE TRIGGER correction_requests_insert_guard BEFORE INSERT ON correction_requests WHEN NEW.status <> 'pending' {$abort('A correction request starts as pending')}");
        DB::statement("CREATE TRIGGER correction_requests_content_guard BEFORE UPDATE OF {$correction} ON correction_requests {$abort('The content of a correction request is immutable')}");
        DB::statement("CREATE TRIGGER correction_requests_final_guard BEFORE UPDATE ON correction_requests WHEN OLD.status <> 'pending' {$abort('A decided correction request is final')}");
        DB::statement("CREATE TRIGGER correction_requests_delete_guard BEFORE DELETE ON correction_requests {$abort('A correction request cannot be deleted')}");
    }
};
