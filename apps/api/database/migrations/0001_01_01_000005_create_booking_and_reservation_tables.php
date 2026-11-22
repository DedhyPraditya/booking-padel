<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist;');
        }

        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('booking_code', 40)->unique();
            $table->string('public_token_hash', 64)->unique();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('venue_id')->constrained('venues')->restrictOnDelete();

            // Immutable customer contact snapshot
            $table->string('customer_name', 150);
            $table->string('customer_phone', 30);
            $table->string('customer_email', 190)->nullable();

            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->string('status', 30)->default('pending_payment')->index();

            // Money columns: numeric(14,2), never float
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->char('currency', 3)->default('IDR');

            $table->timestampTz('hold_expires_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->jsonb('policy_snapshot_json')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['venue_id', 'status', 'created_at']);
            $table->index(['venue_id', 'start_at']);
        });

        Schema::create('booking_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignUuid('court_id')->constrained('courts')->restrictOnDelete();
            $table->string('court_name_snapshot', 100);
            $table->string('court_code_snapshot', 30);
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->unsignedInteger('duration_minutes');
            $table->decimal('unit_price_per_hour', 14, 2);
            $table->decimal('total_price', 14, 2);
            $table->jsonb('pricing_snapshot_json')->nullable();
            $table->timestampsTz();

            $table->index(['court_id', 'start_at', 'end_at']);
        });

        // Authoritative court occupancy table with PostgreSQL exclusion constraint
        Schema::create('court_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignUuid('court_id')->constrained('courts')->restrictOnDelete();
            $table->foreignUuid('booking_id')->nullable()->constrained('bookings')->cascadeOnDelete();
            $table->foreignUuid('booking_item_id')->nullable()->constrained('booking_items')->cascadeOnDelete();
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->string('status', 30)->default('held')->index(); // held | confirmed | released | expired | cancelled
            $table->timestampTz('hold_expires_at')->nullable()->index();
            $table->timestampsTz();

            $table->index(['court_id', 'status', 'start_at', 'end_at']);
        });

        Schema::create('booking_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason', 255)->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['booking_id', 'created_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_valid_time CHECK (end_at > start_at);');
            DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_non_negative_amounts CHECK (subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0);');
            DB::statement('ALTER TABLE booking_items ADD CONSTRAINT booking_items_valid_time CHECK (end_at > start_at);');
            DB::statement('ALTER TABLE booking_items ADD CONSTRAINT booking_items_positive_duration CHECK (duration_minutes > 0);');
            DB::statement('ALTER TABLE booking_items ADD CONSTRAINT booking_items_non_negative_price CHECK (unit_price_per_hour >= 0 AND total_price >= 0);');
            DB::statement('ALTER TABLE court_reservations ADD CONSTRAINT court_reservations_valid_time CHECK (end_at > start_at);');

            // Database-enforced anti-overlap exclusion constraint on half-open interval [start_at, end_at)
            DB::statement("
                ALTER TABLE court_reservations
                ADD CONSTRAINT court_reservations_no_overlap
                EXCLUDE USING gist (
                    court_id WITH =,
                    tstzrange(start_at, end_at, '[)') WITH &&
                )
                WHERE (status IN ('held', 'confirmed'));
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_histories');
        Schema::dropIfExists('court_reservations');
        Schema::dropIfExists('booking_items');
        Schema::dropIfExists('bookings');
    }
};
