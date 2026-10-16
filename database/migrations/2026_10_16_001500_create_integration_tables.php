<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 17 — integration runs and the exception register.
 *
 * Every inbound file and every migration run is recorded with its checksum,
 * the manifest it came with (never the signature), counts, reconciliation,
 * watermark, attempts, and the batch it produced. Problems become
 * exceptions with an owner and a decision. Runs are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_runs', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('connection', 40);
            $table->string('mode', 20);
            $table->string('file_name', 160);
            $table->char('content_hash', 64)->nullable();
            $table->json('manifest')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedInteger('accepted_count')->nullable();
            $table->unsignedInteger('rejected_count')->nullable();
            $table->unsignedInteger('duplicate_count')->nullable();
            $table->json('reconciliation')->nullable();
            $table->boolean('reconciled')->nullable();
            $table->timestampTz('watermark', 6)->nullable();
            $table->string('target_type', 40)->nullable();
            $table->char('target_public_id', 26)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('next_attempt_at', 6)->nullable();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('finished_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index(['connection', 'status']);
            $table->index(['connection', 'content_hash']);
            $table->index(['connection', 'file_name']);
        });

        Schema::create('integration_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('integration_run_id')->nullable()->constrained('integration_runs')->restrictOnDelete();
            $table->string('connection', 40);
            $table->string('kind', 40);
            $table->text('detail');
            $table->string('data_owner', 160);
            $table->string('status', 20)->default('open');
            $table->text('resolution_reason')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index(['connection', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_integration_history_delete()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Integration history is not deleted (%)', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            DB::statement('CREATE TRIGGER integration_runs_no_delete BEFORE DELETE ON integration_runs FOR EACH ROW EXECUTE FUNCTION reject_integration_history_delete()');
            DB::statement('CREATE TRIGGER integration_exceptions_no_delete BEFORE DELETE ON integration_exceptions FOR EACH ROW EXECUTE FUNCTION reject_integration_history_delete()');
            DB::statement("ALTER TABLE integration_runs ADD CONSTRAINT integration_runs_status_check CHECK (status IN ('received', 'quarantined', 'staged', 'committed', 'duplicate', 'failed', 'dead_letter', 'dry_run', 'applied'))");
            DB::statement("ALTER TABLE integration_runs ADD CONSTRAINT integration_runs_mode_check CHECK (mode IN ('inbound', 'migration_dry_run', 'migration_apply'))");
            DB::statement("ALTER TABLE integration_exceptions ADD CONSTRAINT integration_exceptions_status_check CHECK (status IN ('open', 'accepted', 'resolved'))");

            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Step 17 guards require PostgreSQL or SQLite.');
        }

        DB::statement("CREATE TRIGGER integration_runs_no_delete BEFORE DELETE ON integration_runs BEGIN SELECT RAISE(ABORT, 'Integration history is not deleted'); END;");
        DB::statement("CREATE TRIGGER integration_exceptions_no_delete BEFORE DELETE ON integration_exceptions BEGIN SELECT RAISE(ABORT, 'Integration history is not deleted'); END;");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS integration_runs_no_delete ON integration_runs');
            DB::statement('DROP TRIGGER IF EXISTS integration_exceptions_no_delete ON integration_exceptions');
        }

        Schema::dropIfExists('integration_exceptions');
        Schema::dropIfExists('integration_runs');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_integration_history_delete()');
        }
    }
};
