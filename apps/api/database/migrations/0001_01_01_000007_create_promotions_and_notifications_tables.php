<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('discount_type', 20)->default('percentage'); // percentage | fixed
            $table->decimal('discount_value', 14, 2);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();

            $table->unique(['venue_id', 'code']);
        });

        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('promotion_id')->constrained('promotions')->restrictOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('discount_amount', 14, 2);
            $table->timestampsTz();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('booking_id')->nullable()->constrained('bookings')->cascadeOnDelete();
            $table->string('channel', 30)->default('email'); // email | whatsapp
            $table->string('recipient', 190);
            $table->string('template_key', 80);
            $table->string('status', 30)->default('sent')->index(); // queued | sent | failed
            $table->jsonb('payload_json')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_positive_value CHECK (discount_value > 0);');
            DB::statement('ALTER TABLE promotion_redemptions ADD CONSTRAINT promotion_redemptions_non_negative CHECK (discount_amount >= 0);');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('promotions');
    }
};
