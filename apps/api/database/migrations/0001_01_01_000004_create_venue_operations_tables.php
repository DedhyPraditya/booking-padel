<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('venue_id')->constrained('venues')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('code', 30);
            $table->string('court_type', 30)->default('indoor'); // indoor | outdoor
            $table->string('surface_type', 60)->default('panoramic_turf');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index(); // active | inactive | maintenance
            $table->timestampsTz();

            $table->unique(['venue_id', 'code']);
        });

        Schema::create('operating_hours', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->smallInteger('day_of_week'); // 0 (Sunday) .. 6 (Saturday)
            $table->time('open_time');
            $table->time('close_time');
            $table->boolean('is_closed')->default(false);
            $table->timestampsTz();

            $table->unique(['venue_id', 'day_of_week']);
        });

        Schema::create('special_dates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_closed')->default(false);
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->string('note', 190)->nullable();
            $table->timestampsTz();

            $table->unique(['venue_id', 'date']);
        });

        Schema::create('court_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignUuid('court_id')->constrained('courts')->cascadeOnDelete();
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->string('reason', 190);
            $table->string('status', 20)->default('active')->index(); // active | cancelled
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['court_id', 'start_at', 'end_at']);
        });

        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignUuid('court_id')->nullable()->constrained('courts')->cascadeOnDelete();
            $table->string('name', 120);
            $table->smallInteger('day_of_week')->nullable(); // null = all days, or 0..6
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('price_per_hour', 14, 2);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->integer('priority')->default(10);
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();

            $table->index(['venue_id', 'is_active', 'priority']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE operating_hours ADD CONSTRAINT operating_hours_day_range CHECK (day_of_week >= 0 AND day_of_week <= 6);');
            DB::statement('ALTER TABLE operating_hours ADD CONSTRAINT operating_hours_valid_time CHECK (is_closed = true OR close_time > open_time);');
            DB::statement('ALTER TABLE special_dates ADD CONSTRAINT special_dates_valid_time CHECK (is_closed = true OR (open_time IS NOT NULL AND close_time IS NOT NULL AND close_time > open_time));');
            DB::statement('ALTER TABLE court_blocks ADD CONSTRAINT court_blocks_valid_time CHECK (end_at > start_at);');
            DB::statement('ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_non_negative_price CHECK (price_per_hour >= 0);');
            DB::statement('ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_valid_time CHECK (end_time > start_time);');
            DB::statement('ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_day_range CHECK (day_of_week IS NULL OR (day_of_week >= 0 AND day_of_week <= 6));');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('court_blocks');
        Schema::dropIfExists('special_dates');
        Schema::dropIfExists('operating_hours');
        Schema::dropIfExists('courts');
    }
};
