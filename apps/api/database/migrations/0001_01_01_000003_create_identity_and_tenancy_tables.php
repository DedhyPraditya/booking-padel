<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('slug', 180)->unique();
            $table->string('status', 20)->default('active')->index();
            $table->timestampsTz();
        });

        Schema::create('venues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('slug', 180)->unique();
            $table->text('address');
            $table->string('city', 100);
            $table->string('timezone', 60)->default('Asia/Makassar');
            $table->string('phone', 30);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();

            // Configurable venue operational policies (OD-003..OD-010)
            $table->unsignedSmallInteger('slot_interval_minutes')->default(30);
            $table->unsignedSmallInteger('min_booking_duration_minutes')->default(60);
            $table->unsignedSmallInteger('max_booking_duration_minutes')->default(180);
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->unsignedSmallInteger('hold_duration_minutes')->default(10);
            $table->unsignedSmallInteger('cancellation_cutoff_hours')->default(24);
            $table->unsignedSmallInteger('reschedule_cutoff_hours')->default(24);

            $table->timestampsTz();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 60)->unique();
            $table->string('guard_name', 40)->default('api');
            $table->string('description', 190)->nullable();
            $table->timestampsTz();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80)->unique();
            $table->string('guard_name', 40)->default('api');
            $table->string('description', 190)->nullable();
            $table->timestampsTz();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('venue_id')->nullable()->constrained('venues')->cascadeOnDelete();
            $table->timestampsTz();

            $table->index(['user_id', 'role_id']);
            $table->index(['user_id', 'organization_id', 'venue_id']);
        });

        Schema::create('user_venue_access', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['user_id', 'venue_id', 'role_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignUuid('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->string('action', 100)->index();
            $table->string('entity_type', 100);
            $table->uuid('entity_id')->nullable();
            $table->jsonb('before_json')->nullable();
            $table->jsonb('after_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['venue_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('user_venue_access');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('venues');
        Schema::dropIfExists('organizations');
    }
};
