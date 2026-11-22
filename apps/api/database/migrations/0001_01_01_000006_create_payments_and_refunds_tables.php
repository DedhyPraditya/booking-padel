<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->string('bank_name', 80)->default('Bank Mandiri')->after('phone');
            $table->string('bank_account_number', 60)->default('1520037845860')->after('bank_name');
            $table->string('bank_account_holder', 150)->default('NURHASDI NANDAR')->after('bank_account_number');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->string('provider', 40)->default('manual'); // manual | midtrans | xendit
            $table->string('provider_reference', 150)->nullable()->unique();
            $table->string('payment_method', 50)->default('bank_transfer'); // bank_transfer | qris | va
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('IDR');
            $table->string('status', 30)->default('unpaid')->index(); // unpaid | pending | paid | failed | expired | refund_pending | partially_refunded | refunded
            $table->string('idempotency_key', 100)->unique();
            $table->text('proof_file_key')->nullable();
            $table->string('proof_original_name', 190)->nullable();
            $table->string('proof_mime_type', 80)->nullable();
            $table->timestampTz('proof_uploaded_at')->nullable();
            $table->string('sender_bank_name', 80)->nullable();
            $table->string('sender_account_holder', 150)->nullable();
            $table->text('verification_notes')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->jsonb('payment_instructions_json')->nullable();
            $table->timestampsTz();

            $table->index(['booking_id', 'status']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 40);
            $table->string('event_key', 150);
            $table->string('provider_reference', 150)->nullable()->index();
            $table->jsonb('payload_json');
            $table->boolean('signature_valid')->default(false);
            $table->string('processing_status', 40)->default('received'); // received | processed | ignored | failed
            $table->text('error_message')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'event_key']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('payments')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reason', 255);
            $table->string('provider_reference', 150)->nullable();
            $table->string('status', 30)->default('pending')->index(); // pending | approved | processed | rejected
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_non_negative_amount CHECK (amount >= 0);');
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_positive_amount CHECK (amount > 0);');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payments');

        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bank_account_number', 'bank_account_holder']);
        });
    }
};
