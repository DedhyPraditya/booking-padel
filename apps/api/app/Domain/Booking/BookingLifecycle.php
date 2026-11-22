<?php

namespace App\Domain\Booking;

use InvalidArgumentException;

class BookingLifecycle
{
    /**
     * Explicit map of allowed booking status transitions (Section 4.6).
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'pending_payment' => ['confirmed', 'cancelled', 'expired'],
        'confirmed' => ['checked_in', 'completed', 'no_show', 'cancelled', 'refund_pending'],
        'checked_in' => ['completed'],
        'completed' => [],
        'cancelled' => ['refund_pending', 'refunded'],
        'expired' => [],
        'no_show' => [],
        'refund_pending' => ['refunded'],
        'refunded' => [],
    ];

    public static function canTransition(string $fromStatus, string $toStatus): bool
    {
        return in_array($toStatus, self::ALLOWED_TRANSITIONS[$fromStatus] ?? [], true);
    }

    public static function assertTransitionAllowed(string $fromStatus, string $toStatus): void
    {
        if (! self::canTransition($fromStatus, $toStatus)) {
            throw new InvalidArgumentException(
                sprintf('Transisi status booking dari "%s" ke "%s" tidak diizinkan.', $fromStatus, $toStatus)
            );
        }
    }

    /**
     * Map booking status to the corresponding court_reservations status.
     */
    public static function reservationStatusForBookingStatus(string $bookingStatus): string
    {
        return match ($bookingStatus) {
            'pending_payment' => 'held',
            'confirmed', 'checked_in', 'completed', 'no_show' => 'confirmed',
            'expired' => 'expired',
            'cancelled', 'refund_pending', 'refunded' => 'cancelled',
            default => 'released',
        };
    }
}
