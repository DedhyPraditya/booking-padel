<?php

namespace App\Domain\Payment;

use App\Models\Booking;
use App\Models\Payment;

/**
 * Provider abstraction (Section 6.1) so Midtrans/Xendit adapters can be plugged in
 * once owner approves gateway credentials without altering booking or payment domain logic.
 */
interface PaymentGatewayInterface
{
    /**
     * @return array<string, mixed>
     */
    public function createPaymentInstruction(Booking $booking, Payment $payment): array;

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $payload
     */
    public function verifyWebhookSignature(array $headers, array $payload): bool;

    public function normalizeProviderStatus(string $providerStatus): string;
}
