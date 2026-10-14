<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 12 — report exports.
 *
 * One row per requested export: who asked, which report and format, the
 * validated filters, the organization scope it was generated under, its
 * progress and outcome, and where the private file lives. The request
 * fingerprint makes an identical request reuse the export that is still
 * queued or running instead of starting a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('report_key', 60);
            $table->string('format', 10);
            $table->json('filters');
            $table->char('request_fingerprint', 64);
            $table->string('status', 20);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('rows_written')->default(0);
            $table->string('failure_code', 60)->nullable();
            $table->string('scope_summary', 500)->nullable();
            $table->string('classification', 30);
            $table->string('disk', 40)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('file_name', 160)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('expires_at', 6)->nullable();
            $table->timestampTz('purged_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        // At most one export in flight per identical request of one account.
        DB::statement("CREATE UNIQUE INDEX report_exports_in_flight_unique ON report_exports (user_id, request_fingerprint) WHERE status IN ('queued', 'running')");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check CHECK (status IN ('queued', 'running', 'completed', 'failed', 'expired'))");
            DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_format_check CHECK (format IN ('xlsx', 'pdf'))");
            DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_file_check CHECK (status <> 'completed' OR (path IS NOT NULL AND expires_at IS NOT NULL AND checksum_sha256 IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
