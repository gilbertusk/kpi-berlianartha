<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 13 — one generic register for the operational records a team keeps
 * as the source of a KPI (Design revisions, costing comparisons, ...).
 *
 * Every record type shares this table; its fields are validated by the
 * record type in the application and kept in `details`. A record is
 * never edited or deleted: a mistake is voided with a reason and recorded
 * again, so the attribution of every count stays auditable. The source
 * system and reference identify the record in the system it came from, so
 * the same source record cannot be registered twice.
 */
return new class extends Migration
{
    private const CONTENT = 'public_id, record_type, organization_unit_id, occurred_on, source_system, source_reference, kpi_definition_id, details, recorded_by_user_id, created_at';

    public function up(): void
    {
        Schema::create('operational_records', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('record_type', 40);
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->date('occurred_on');
            $table->string('source_system', 40);
            $table->string('source_reference', 160);
            $table->foreignId('kpi_definition_id')->nullable()->constrained('kpi_definitions')->restrictOnDelete();
            $table->json('details');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('voided_at', 6)->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('void_reason')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->index(['record_type', 'organization_unit_id', 'occurred_on']);
        });

        // A live record of a team is registered once, whether it was typed in
        // or delivered by an adapter; a voided one may be registered again.
        // Teams never block each other: the team is part of the key.
        DB::statement('CREATE UNIQUE INDEX operational_records_source_unique ON operational_records (record_type, organization_unit_id, source_reference) WHERE voided_at IS NULL');

        $content = self::CONTENT;

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_operational_record_change()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Operational records are not edited or deleted; void and record again (%)', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            DB::statement("CREATE TRIGGER operational_records_content_guard BEFORE UPDATE OF {$content} ON operational_records FOR EACH ROW EXECUTE FUNCTION reject_operational_record_change()");
            DB::statement('CREATE TRIGGER operational_records_final_guard BEFORE UPDATE ON operational_records FOR EACH ROW WHEN (OLD.voided_at IS NOT NULL) EXECUTE FUNCTION reject_operational_record_change()');
            DB::statement('CREATE TRIGGER operational_records_delete_guard BEFORE DELETE ON operational_records FOR EACH ROW EXECUTE FUNCTION reject_operational_record_change()');
            DB::statement('ALTER TABLE operational_records ADD CONSTRAINT operational_records_void_check CHECK ((voided_at IS NULL) = (voided_by_user_id IS NULL) AND (voided_at IS NULL) = (void_reason IS NULL))');

            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Step 13 guards require PostgreSQL or SQLite.');
        }

        DB::statement("CREATE TRIGGER operational_records_content_guard BEFORE UPDATE OF {$content} ON operational_records BEGIN SELECT RAISE(ABORT, 'Operational records are not edited; void and record again'); END;");
        DB::statement("CREATE TRIGGER operational_records_final_guard BEFORE UPDATE ON operational_records WHEN OLD.voided_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'A voided operational record is final'); END;");
        DB::statement("CREATE TRIGGER operational_records_delete_guard BEFORE DELETE ON operational_records BEGIN SELECT RAISE(ABORT, 'Operational records are not deleted'); END;");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['content', 'final', 'delete'] as $guard) {
                DB::statement("DROP TRIGGER IF EXISTS operational_records_{$guard}_guard ON operational_records");
            }

            DB::unprepared('DROP FUNCTION IF EXISTS reject_operational_record_change()');
        }

        Schema::dropIfExists('operational_records');
    }
};
