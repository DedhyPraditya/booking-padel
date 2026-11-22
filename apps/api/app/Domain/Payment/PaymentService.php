<?php

namespace App\Domain\Payment;

use App\Domain\Booking\BookingService;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly BookingService $bookingService,
    ) {}

    /**
     * Create or return an existing manual bank transfer payment instruction idempotently.
     */
    public function createOrGetManualPayment(Booking $booking, ?string $idempotencyKey = null): Payment
    {
        $booking->loadMissing('venue');
        $venue = $booking->venue;

        $key = $idempotencyKey ?: "manual-{$booking->id}";

        $existingByKey = Payment::where('idempotency_key', $key)->first();
        if ($existingByKey) {
            return $existingByKey;
        }

        $existingActive = Payment::query()
            ->where('booking_id', $booking->id)
            ->whereIn('status', ['unpaid', 'pending', 'paid'])
            ->latest()
            ->first();

        if ($existingActive) {
            return $existingActive;
        }

        if (! in_array($booking->status, ['pending_payment', 'confirmed'], true)) {
            throw ValidationException::withMessages([
                'booking_id' => ['Booking sudah tidak aktif atau telah kedaluwarsa.'],
            ]);
        }

        return Payment::create([
            'booking_id' => $booking->id,
            'provider' => 'manual',
            'provider_reference' => 'MNL-'.$booking->booking_code.'-'.strtoupper(Str::random(4)),
            'payment_method' => 'bank_transfer',
            'amount' => $booking->total_amount,
            'currency' => $booking->currency ?: 'IDR',
            'status' => $booking->status === 'confirmed' ? 'paid' : 'unpaid',
            'idempotency_key' => $key,
            'paid_at' => $booking->status === 'confirmed' ? now() : null,
            'payment_instructions_json' => [
                'bank_name' => $venue?->bank_name ?: 'Bank Mandiri',
                'bank_account_number' => $venue?->bank_account_number ?: '1520037845860',
                'bank_account_holder' => $venue?->bank_account_holder ?: 'NURHASDI NANDAR',
                'whatsapp_admin' => $venue?->phone ?: '082347838252',
                'booking_code' => $booking->booking_code,
                'amount' => $booking->total_amount,
            ],
        ]);
    }

    /**
     * Upload manual transfer proof and mark payment as pending verification.
     */
    public function uploadManualProof(
        Booking $booking,
        UploadedFile $file,
        ?string $senderBankName = null,
        ?string $senderAccountHolder = null,
    ): Payment {
        if ($booking->status !== 'pending_payment') {
            throw ValidationException::withMessages([
                'booking' => ['Bukti transfer hanya dapat diunggah untuk booking yang sedang menunggu pembayaran.'],
            ]);
        }

        if ($booking->hold_expires_at !== null && $booking->hold_expires_at->isPast()) {
            $this->bookingService->expireOverdueHolds();
            throw ValidationException::withMessages([
                'booking' => ['Batas waktu pembayaran untuk booking ini telah habis. Silakan buat booking baru.'],
            ]);
        }

        $payment = $this->createOrGetManualPayment($booking);

        if ($payment->status === 'paid') {
            throw ValidationException::withMessages([
                'payment' => ['Pembayaran untuk booking ini sudah terverifikasi lunas.'],
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $storageKey = sprintf(
            'payment-proofs/%s/%s-%s.%s',
            now()->format('Y/m'),
            $booking->booking_code,
            Str::random(12),
            $extension
        );

        $file->storeAs(dirname($storageKey), basename($storageKey), 'local');

        return DB::transaction(function () use ($payment, $storageKey, $file, $senderBankName, $senderAccountHolder) {
            $payment->update([
                'status' => 'pending',
                'proof_file_key' => $storageKey,
                'proof_original_name' => substr($file->getClientOriginalName(), 0, 190),
                'proof_mime_type' => $file->getClientMimeType(),
                'proof_uploaded_at' => now(),
                'sender_bank_name' => $senderBankName,
                'sender_account_holder' => $senderAccountHolder,
            ]);

            return $payment->fresh();
        });
    }

    /**
     * Authorized finance/admin verification of a manual bank transfer.
     */
    public function verifyManualPayment(
        Payment $payment,
        string $decision,
        User $verifier,
        ?string $notes = null,
    ): Payment {
        return DB::transaction(function () use ($payment, $decision, $verifier, $notes) {
            $lockedPayment = Payment::with('booking.venue')->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $booking = $lockedPayment->booking;

            if (! $booking) {
                throw ValidationException::withMessages([
                    'payment' => ['Data booking terkait pembayaran tidak ditemukan.'],
                ]);
            }

            if ($lockedPayment->status === 'paid') {
                throw ValidationException::withMessages([
                    'payment' => ['Pembayaran ini sudah diverifikasi lunas sebelumnya.'],
                ]);
            }

            $before = $lockedPayment->toArray();

            if ($decision === 'approve') {
                $lockedPayment->update([
                    'status' => 'paid',
                    'verified_by' => $verifier->id,
                    'verified_at' => now(),
                    'paid_at' => now(),
                    'verification_notes' => $notes ?: 'Bukti transfer diverifikasi valid oleh admin.',
                ]);

                if ($booking->status === 'pending_payment') {
                    $this->bookingService->transitionStatus(
                        booking: $booking,
                        toStatus: 'confirmed',
                        reason: 'Pembayaran transfer manual diverifikasi oleh '.$verifier->name,
                        actor: $verifier,
                    );
                }

                AuditLogger::record(
                    action: 'payment.verified.approved',
                    entityType: 'payment',
                    entityId: $lockedPayment->id,
                    before: $before,
                    after: $lockedPayment->fresh()?->toArray(),
                    actor: $verifier,
                    organizationId: $booking->venue?->organization_id,
                    venueId: $booking->venue_id,
                );
            } else {
                if (empty($notes)) {
                    throw ValidationException::withMessages([
                        'notes' => ['Alasan penolakan bukti transfer wajib diisi.'],
                    ]);
                }

                $lockedPayment->update([
                    'status' => 'failed',
                    'verified_by' => $verifier->id,
                    'verified_at' => now(),
                    'verification_notes' => $notes,
                ]);

                AuditLogger::record(
                    action: 'payment.verified.rejected',
                    entityType: 'payment',
                    entityId: $lockedPayment->id,
                    before: $before,
                    after: $lockedPayment->fresh()?->toArray(),
                    actor: $verifier,
                    organizationId: $booking->venue?->organization_id,
                    venueId: $booking->venue_id,
                );
            }

            return $lockedPayment->fresh(['booking.items', 'booking.venue', 'verifier:id,name,email']);
        });
    }

    /**
     * Record a refund for a paid payment without deleting original payment records.
     */
    public function createRefund(Payment $payment, float $amount, string $reason, User $actor): Refund
    {
        return DB::transaction(function () use ($payment, $amount, $reason, $actor) {
            $lockedPayment = Payment::with('booking.venue')->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedPayment->status, ['paid', 'partially_refunded'], true)) {
                throw ValidationException::withMessages([
                    'payment_id' => ['Refund hanya dapat diajukan untuk pembayaran yang sudah lunas.'],
                ]);
            }

            $alreadyRefunded = (float) Refund::where('payment_id', $lockedPayment->id)
                ->whereIn('status', ['approved', 'processed'])
                ->sum('amount');

            $maxRefundable = round((float) $lockedPayment->amount - $alreadyRefunded, 2);

            if ($amount <= 0 || $amount > $maxRefundable) {
                throw ValidationException::withMessages([
                    'amount' => [sprintf('Nominal refund tidak valid. Maksimal yang dapat direfund: Rp %s.', number_format($maxRefundable, 0, ',', '.'))],
                ]);
            }

            $refund = Refund::create([
                'payment_id' => $lockedPayment->id,
                'amount' => number_format($amount, 2, '.', ''),
                'reason' => $reason,
                'status' => 'processed',
                'requested_by' => $actor->id,
                'approved_by' => $actor->id,
                'processed_at' => now(),
            ]);

            $newTotalRefunded = round($alreadyRefunded + $amount, 2);
            $newPaymentStatus = $newTotalRefunded >= (float) $lockedPayment->amount
                ? 'refunded'
                : 'partially_refunded';

            $lockedPayment->update(['status' => $newPaymentStatus]);

            $booking = $lockedPayment->booking;
            if ($booking && $newPaymentStatus === 'refunded' && in_array($booking->status, ['confirmed', 'cancelled', 'refund_pending'], true)) {
                if ($booking->status === 'confirmed') {
                    $this->bookingService->transitionStatus($booking, 'cancelled', 'Dibatalkan karena refund penuh.', $actor);
                    $booking->refresh();
                }
                if ($booking->status === 'cancelled') {
                    $this->bookingService->transitionStatus($booking, 'refund_pending', $reason, $actor);
                    $booking->refresh();
                }
                if ($booking->status === 'refund_pending') {
                    $this->bookingService->transitionStatus($booking, 'refunded', $reason, $actor);
                }
            }

            AuditLogger::record(
                action: 'payment.refund.processed',
                entityType: 'refund',
                entityId: $refund->id,
                before: null,
                after: $refund->toArray(),
                actor: $actor,
                organizationId: $booking?->venue?->organization_id,
                venueId: $booking?->venue_id,
            );

            return $refund;
        });
    }
}
