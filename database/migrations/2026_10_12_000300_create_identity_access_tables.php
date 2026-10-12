<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Step 05 — account lifecycle, role assignments separated from organization
 * scope, and time-boxed delegations (ADR-0009).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->char('public_id', 26)->nullable()->unique();
            $table->string('status', 20)->default('active');
            $table->timestampTz('deactivated_at', 6)->nullable();
            $table->text('deactivation_reason')->nullable();
            $table->timestampTz('last_login_at', 6)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->foreignId('provisioned_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->index('status');
        });

        DB::table('users')->whereNull('public_id')->orderBy('id')->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update(['public_id' => (string) Str::ulid()]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->char('public_id', 26)->nullable(false)->change();
        });

        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 32);
            $table->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->text('reason');
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('revoke_reason')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->index(['user_id', 'role']);
            $table->index(['role', 'organization_unit_id']);
        });

        Schema::create('delegations', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('source_role_assignment_id')->constrained('role_assignments')->restrictOnDelete();
            $table->foreignId('delegator_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 32);
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_until');
            $table->text('reason');
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('revoke_reason')->nullable();
            $table->timestampTz('expiry_recorded_at', 6)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz(6);

            $table->index(['delegate_user_id', 'effective_from']);
            $table->index('delegator_user_id');
            $table->index(['effective_until', 'expiry_recorded_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_effective_period_check CHECK (effective_until IS NULL OR effective_until > effective_from)');
            DB::statement('ALTER TABLE delegations ADD CONSTRAINT delegations_effective_period_check CHECK (effective_until > effective_from)');
            DB::statement('ALTER TABLE delegations ADD CONSTRAINT delegations_not_self_check CHECK (delegator_user_id <> delegate_user_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
        Schema::dropIfExists('role_assignments');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('provisioned_by_user_id');
            $table->dropIndex(['status']);
            $table->dropUnique(['public_id']);
            $table->dropColumn([
                'public_id',
                'status',
                'deactivated_at',
                'deactivation_reason',
                'last_login_at',
                'lock_version',
            ]);
        });
    }
};
