<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('actor_identifier', 64)->nullable();
            $table->string('actor_type', 32);
            $table->string('actor_role', 64)->nullable();
            $table->json('actor_scope')->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 160);
            $table->string('entity_id', 100);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->text('reason')->nullable();
            $table->string('source', 64);
            $table->string('source_reference', 191)->nullable();
            $table->string('correlation_id', 64);
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_identifier', 'occurred_at']);
            $table->index('correlation_id');
            $table->index('occurred_at');
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 100);
            $table->char('key_hash', 64);
            $table->char('request_fingerprint', 64);
            $table->string('status', 32);
            $table->text('response_payload')->nullable();
            $table->timestampTz('expires_at', 6)->nullable();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['scope', 'key_hash']);
            $table->index('expires_at');
        });

        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('event_type', 120);
            $table->unsignedSmallInteger('event_version');
            $table->string('aggregate_type', 120);
            $table->string('aggregate_id', 100);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->json('headers');
            $table->char('deduplication_key', 64)->nullable()->unique();
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('available_at', 6);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('processed_at', 6)->nullable();
            $table->timestampTz('failed_at', 6)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestampsTz(6);

            $table->index(['processed_at', 'failed_at', 'available_at'], 'outbox_dispatch_index');
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        Schema::create('outbox_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbox_message_id')
                ->constrained('outbox_messages')
                ->cascadeOnDelete();
            $table->string('consumer_name', 120);
            $table->timestampTz('processed_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->unique(['outbox_message_id', 'consumer_name']);
        });

        Schema::create('stored_object_metadata', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('disk', 64);
            $table->string('path', 512);
            $table->string('original_name');
            $table->string('content_type', 128)->nullable();
            $table->unsignedBigInteger('byte_size');
            $table->string('checksum_algorithm', 16);
            $table->string('checksum', 128);
            $table->string('classification', 32);
            $table->string('owner_type', 120)->nullable();
            $table->string('owner_id', 100)->nullable();
            $table->string('source_system', 64)->nullable();
            $table->string('source_reference', 191)->nullable();
            $table->string('status', 32)->default('pending');
            $table->json('metadata');
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->string('created_by', 64)->nullable();
            $table->timestampsTz(6);

            $table->unique(['disk', 'path']);
            $table->index(['owner_type', 'owner_id']);
            $table->index(['source_system', 'source_reference']);
        });

        $this->createAuditImmutabilityGuard();
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_object_metadata');
        Schema::dropIfExists('outbox_deliveries');
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_logs');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_audit_log_mutation()');
        }
    }

    private function createAuditImmutabilityGuard(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_audit_log_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs are append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER audit_logs_no_update_or_delete
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_mutation();
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                BEGIN
                    SELECT RAISE(ABORT, 'audit_logs are append-only');
                END;
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                BEGIN
                    SELECT RAISE(ABORT, 'audit_logs are append-only');
                END;
                SQL);

            return;
        }

        throw new \RuntimeException('Step 04 audit immutability requires PostgreSQL or SQLite.');
    }
};
