<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 14 — read model of accounts receivable.
 *
 * The KPI system is not a ledger. It keeps snapshots of what the accounting
 * system reported on an as-of date: invoices, payment allocations, and tax
 * invoice references. A snapshot is staged, checked, and committed whole;
 * once committed it never changes. A new file for the same as-of date is a
 * new snapshot version beside the old one, never an overwrite. The same file
 * (by content hash) is never staged twice.
 *
 * Amounts are numeric(18,2) in the document currency; nothing is converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ar_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->string('source_system', 40);
            $table->date('as_of_date');
            $table->unsignedInteger('version');
            $table->char('content_hash', 64);
            $table->string('original_name', 160);
            $table->string('status', 20);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->decimal('control_invoice_total', 18, 2)->nullable();
            $table->decimal('control_allocation_total', 18, 2)->nullable();
            $table->foreignId('staged_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('committed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('committed_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['organization_unit_id', 'source_system', 'as_of_date', 'version'], 'ar_snapshots_version_unique');
        });

        Schema::create('ar_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ar_snapshot_id')->constrained('ar_snapshots')->restrictOnDelete();
            $table->unsignedInteger('source_line');
            $table->string('document_type', 20);
            $table->string('document_number', 60);
            $table->string('related_document', 60)->nullable();
            $table->string('customer_code', 40)->nullable();
            $table->string('customer_name', 120)->nullable();
            $table->date('document_date');
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('status', 40)->nullable();
            $table->string('status_reason', 255)->nullable();
            $table->string('collection_owner', 120)->nullable();
            $table->string('next_action', 255)->nullable();
            $table->date('next_action_date')->nullable();
            $table->char('row_hash', 64);
            $table->timestampsTz(6);

            $table->index(['ar_snapshot_id', 'document_type', 'document_number']);
            $table->index(['ar_snapshot_id', 'related_document']);
        });

        // A file is imported once per source system; a rejected import may be repeated.
        DB::statement("CREATE UNIQUE INDEX ar_snapshots_content_unique ON ar_snapshots (source_system, content_hash) WHERE status <> 'rejected'");

        // One invoice and one tax invoice number per snapshot; a payment may be split over invoices.
        DB::statement("CREATE UNIQUE INDEX ar_documents_invoice_unique ON ar_documents (ar_snapshot_id, document_type, document_number) WHERE document_type IN ('invoice', 'tax_invoice')");
        DB::statement("CREATE UNIQUE INDEX ar_documents_allocation_unique ON ar_documents (ar_snapshot_id, document_number, related_document) WHERE document_type = 'allocation'");

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_ar_change()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'A committed receivables snapshot is not changed (%)', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;

                -- PostgreSQL does not allow a subquery in a trigger WHEN condition,
                -- so the parent snapshot status is read inside the function.
                CREATE OR REPLACE FUNCTION reject_ar_document_change()
                RETURNS trigger AS $$
                DECLARE
                    snapshot_status varchar;
                BEGIN
                    SELECT status INTO snapshot_status FROM ar_snapshots
                        WHERE id = CASE WHEN TG_OP = 'INSERT' THEN NEW.ar_snapshot_id ELSE OLD.ar_snapshot_id END;

                    IF snapshot_status IS DISTINCT FROM 'staged' THEN
                        RAISE EXCEPTION 'A committed receivables snapshot is not changed (%)', TG_TABLE_NAME;
                    END IF;

                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            DB::statement("CREATE TRIGGER ar_snapshots_final_guard BEFORE UPDATE OR DELETE ON ar_snapshots FOR EACH ROW WHEN (OLD.status <> 'staged') EXECUTE FUNCTION reject_ar_change()");
            DB::statement('CREATE TRIGGER ar_documents_final_guard BEFORE INSERT OR UPDATE OR DELETE ON ar_documents FOR EACH ROW EXECUTE FUNCTION reject_ar_document_change()');
            DB::statement("ALTER TABLE ar_snapshots ADD CONSTRAINT ar_snapshots_status_check CHECK (status IN ('staged', 'committed', 'rejected'))");
            DB::statement("ALTER TABLE ar_documents ADD CONSTRAINT ar_documents_type_check CHECK (document_type IN ('invoice', 'allocation', 'tax_invoice'))");

            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Step 14 guards require PostgreSQL or SQLite.');
        }

        DB::statement("CREATE TRIGGER ar_snapshots_update_guard BEFORE UPDATE ON ar_snapshots WHEN OLD.status <> 'staged' BEGIN SELECT RAISE(ABORT, 'A committed receivables snapshot is not changed'); END;");
        DB::statement("CREATE TRIGGER ar_snapshots_delete_guard BEFORE DELETE ON ar_snapshots WHEN OLD.status <> 'staged' BEGIN SELECT RAISE(ABORT, 'A committed receivables snapshot is not deleted'); END;");
        DB::statement("CREATE TRIGGER ar_documents_update_guard BEFORE UPDATE ON ar_documents WHEN (SELECT status FROM ar_snapshots WHERE id = OLD.ar_snapshot_id) <> 'staged' BEGIN SELECT RAISE(ABORT, 'A committed receivables snapshot is not changed'); END;");
        DB::statement("CREATE TRIGGER ar_documents_delete_guard BEFORE DELETE ON ar_documents WHEN (SELECT status FROM ar_snapshots WHERE id = OLD.ar_snapshot_id) <> 'staged' BEGIN SELECT RAISE(ABORT, 'A committed receivables snapshot is not deleted'); END;");
        DB::statement("CREATE TRIGGER ar_documents_insert_guard BEFORE INSERT ON ar_documents WHEN (SELECT status FROM ar_snapshots WHERE id = NEW.ar_snapshot_id) IS NOT 'staged' BEGIN SELECT RAISE(ABORT, 'A committed receivables snapshot is not changed'); END;");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS ar_snapshots_final_guard ON ar_snapshots');
            DB::statement('DROP TRIGGER IF EXISTS ar_documents_final_guard ON ar_documents');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_ar_document_change()');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_ar_change()');
        }

        Schema::dropIfExists('ar_documents');
        Schema::dropIfExists('ar_snapshots');
    }
};
