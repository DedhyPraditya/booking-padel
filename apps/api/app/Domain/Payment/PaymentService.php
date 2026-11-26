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
use Illuminate\Support\Facades\Storage;
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

        $sanitized = $this->sanitizeAndReencodeProofImage($file);

        $storageKey = sprintf(
            'payment-proofs/%s/%s.%s',
            now()->format('Y/m'),
            (string) Str::uuid(),
            $sanitized['extension']
        );

        Storage::disk('local')->put($storageKey, $sanitized['binary']);

        $previousFileKey = $payment->proof_file_key;
        $safeDisplayName = $this->buildSafeDisplayFilename(
            $file->getClientOriginalName(),
            $sanitized['extension']
        );

        $updatedPayment = DB::transaction(function () use ($payment, $storageKey, $sanitized, $safeDisplayName, $senderBankName, $senderAccountHolder) {
            $payment->update([
                'status' => 'pending',
                'proof_file_key' => $storageKey,
                'proof_original_name' => $safeDisplayName,
                'proof_mime_type' => $sanitized['mime_type'],
                'proof_uploaded_at' => now(),
                'sender_bank_name' => $senderBankName,
                'sender_account_holder' => $senderAccountHolder,
            ]);

            return $payment->fresh();
        });

        if (! empty($previousFileKey) && $previousFileKey !== $storageKey) {
            Storage::disk('local')->delete($previousFileKey);
        }

        return $updatedPayment;
    }

    /**
     * Decode pixel stream via GD and re-encode into a brand-new image buffer.
     * Strips all EXIF metadata, polyglot payloads, and embedded scripts.
     *
     * @return array{binary: string, extension: string, mime_type: string}
     */
    private function sanitizeAndReencodeProofImage(UploadedFile $file): array
    {
        $realPath = $file->getRealPath();
        if (! $realPath || ! is_file($realPath)) {
            throw ValidationException::withMessages([
                'proof_file' => ['File bukti transfer tidak dapat dibaca oleh server.'],
            ]);
        }

        $imageInfo = @getimagesize($realPath);
        if ($imageInfo === false) {
            throw ValidationException::withMessages([
                'proof_file' => ['File harus berupa gambar asli (JPG, PNG, atau WEBP).'],
            ]);
        }

        [$width, $height, $imageType] = $imageInfo;

        if ($width < 80 || $height < 80 || $width > 6000 || $height > 6000 || ($width * $height) > 25_000_000) {
            throw ValidationException::withMessages([
                'proof_file' => ['Resolusi gambar tidak valid (minimal 80x80 px, maksimal 6000x6000 px).'],
            ]);
        }

        $source = match ($imageType) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($realPath),
            IMAGETYPE_PNG => @imagecreatefrompng($realPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($realPath) : false,
            default => false,
        };

        if ($source === false) {
            throw ValidationException::withMessages([
                'proof_file' => ['File gambar bukti transfer rusak atau mengandung format tidak didukung.'],
            ]);
        }

        // Preserve upright orientation on mobile camera JPEGs before stripping EXIF
        if ($imageType === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($realPath);
            $orientation = (int) ($exif['Orientation'] ?? 1);
            $rotated = match ($orientation) {
                3 => @imagerotate($source, 180, 0),
                6 => @imagerotate($source, -90, 0),
                8 => @imagerotate($source, 90, 0),
                default => false,
            };
            if ($rotated !== false) {
                imagedestroy($source);
                $source = $rotated;
                $width = imagesx($source);
                $height = imagesy($source);
            }
        }

        $maxDimension = 1800;
        $targetWidth = $width;
        $targetHeight = $height;

        if ($width > $maxDimension || $height > $maxDimension) {
            $ratio = min($maxDimension / $width, $maxDimension / $height);
            $targetWidth = max(1, (int) round($width * $ratio));
            $targetHeight = max(1, (int) round($height * $ratio));
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            imagedestroy($source);
            throw ValidationException::withMessages([
                'proof_file' => ['Gagal memproses gambar bukti transfer.'],
            ]);
        }

        if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_WEBP) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
        } else {
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
        }

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        if ($imageType === IMAGETYPE_PNG) {
            $ok = imagepng($canvas, null, 7);
            $ext = 'png';
            $mime = 'image/png';
        } elseif ($imageType === IMAGETYPE_WEBP && function_exists('imagewebp')) {
            $ok = imagewebp($canvas, null, 85);
            $ext = 'webp';
            $mime = 'image/webp';
        } else {
            $ok = imagejpeg($canvas, null, 85);
            $ext = 'jpg';
            $mime = 'image/jpeg';
        }
        $binary = (string) ob_get_clean();
        imagedestroy($canvas);

        if (! $ok || $binary === '') {
            throw ValidationException::withMessages([
                'proof_file' => ['Gagal menyimpan ulang gambar bukti transfer yang aman.'],
            ]);
        }

        return [
            'binary' => $binary,
            'extension' => $ext,
            'mime_type' => $mime,
        ];
    }

    private function buildSafeDisplayFilename(string $originalName, string $safeExtension): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $cleanBase = preg_replace('/[^A-Za-z0-9_-]/', '_', $base) ?: 'bukti_transfer';
        $cleanBase = trim(substr($cleanBase, 0, 80), '_-') ?: 'bukti_transfer';

        return "{$cleanBase}.{$safeExtension}";
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
