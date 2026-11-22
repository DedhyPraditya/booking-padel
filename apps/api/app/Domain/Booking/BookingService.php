<?php

namespace App\Domain\Booking;

use App\Domain\Availability\AvailabilityEngine;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Promotion\PromotionService;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BookingStatusHistory;
use App\Models\Court;
use App\Models\CourtReservation;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\User;
use App\Models\Venue;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BookingService
{
    public function __construct(
        private readonly AvailabilityEngine $availabilityEngine,
        private readonly PricingEngine $pricingEngine,
        private readonly PromotionService $promotionService,
    ) {}

    /**
     * Normalize and merge adjacent or overlapping requested slots on the same court
     * so selecting 08:00-09:00 and 09:00-10:00 becomes a clean [08:00, 10:00) interval.
     *
     * @param  array<int, array{court_id: string, start_at: string, end_at: string}>  $rawItems
     * @return array<int, array{court_id: string, start_at: string, end_at: string}>
     */
    public function normalizeAndMergeRequestedItems(array $rawItems): array
    {
        $byCourt = [];
        foreach ($rawItems as $raw) {
            $start = CarbonImmutable::parse($raw['start_at'])->utc();
            $end = CarbonImmutable::parse($raw['end_at'])->utc();
            if ($end->lessThanOrEqualTo($start)) {
                throw ValidationException::withMessages([
                    'start_at' => ['Waktu selesai harus lebih besar dari waktu mulai.'],
                ]);
            }
            $byCourt[$raw['court_id']][] = [
                'court_id' => $raw['court_id'],
                'start' => $start,
                'end' => $end,
            ];
        }

        $merged = [];
        foreach ($byCourt as $courtId => $intervals) {
            usort($intervals, fn ($a, $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

            $current = $intervals[0];
            for ($i = 1, $count = count($intervals); $i < $count; $i++) {
                $next = $intervals[$i];
                if ($next['start']->lessThan($current['end'])) {
                    throw ValidationException::withMessages([
                        'items' => ['Terdapat pilihan slot yang saling bertumpuk pada lapangan yang sama.'],
                    ]);
                }
                if ($next['start']->equalTo($current['end'])) {
                    $current['end'] = $next['end'];
                } else {
                    $merged[] = [
                        'court_id' => $courtId,
                        'start_at' => $current['start']->toIso8601String(),
                        'end_at' => $current['end']->toIso8601String(),
                    ];
                    $current = $next;
                }
            }

            $merged[] = [
                'court_id' => $courtId,
                'start_at' => $current['start']->toIso8601String(),
                'end_at' => $current['end']->toIso8601String(),
            ];
        }

        return $merged;
    }

    /**
     * Create a temporary booking hold or staff-confirmed booking atomically.
     * Supports single-slot or multi-slot / multi-court (tournament/group) bookings.
     *
     * @param  array{
     *     venue_id: string,
     *     court_id?: string|null,
     *     start_at?: string|null,
     *     end_at?: string|null,
     *     items?: array<int, array{court_id: string, start_at: string, end_at: string}>,
     *     customer_name: string,
     *     customer_phone: string,
     *     customer_email?: string|null,
     *     notes?: string|null,
     *     confirm_immediately?: bool
     * }  $payload
     * @return array{booking: Booking, public_token: string}
     */
    public function createBookingHold(array $payload, ?User $actor = null): array
    {
        $venue = Venue::where('status', 'active')->findOrFail($payload['venue_id']);

        $rawItems = ! empty($payload['items'])
            ? $payload['items']
            : [[
                'court_id' => (string) $payload['court_id'],
                'start_at' => (string) $payload['start_at'],
                'end_at' => (string) $payload['end_at'],
            ]];

        $mergedItems = $this->normalizeAndMergeRequestedItems($rawItems);

        $courtIds = collect($mergedItems)->pluck('court_id')->unique()->values();
        foreach ($courtIds as $cId) {
            $this->expireHoldsForCourt($cId);
        }

        $preparedItems = [];
        $subtotalFloat = 0.0;
        $overallStartUtc = null;
        $overallEndUtc = null;

        try {
            foreach ($mergedItems as $item) {
                $court = Court::where('venue_id', $venue->id)->findOrFail($item['court_id']);
                $startAt = CarbonImmutable::parse($item['start_at']);
                $endAt = CarbonImmutable::parse($item['end_at']);

                $this->availabilityEngine->assertSlotIsBookable($venue, $court, $startAt, $endAt);
                $quote = $this->pricingEngine->quote($venue, $court, $startAt, $endAt);

                $subtotalFloat += (float) $quote['total_amount'];

                if ($overallStartUtc === null || $startAt->utc()->lessThan($overallStartUtc)) {
                    $overallStartUtc = $startAt->utc();
                }
                if ($overallEndUtc === null || $endAt->utc()->greaterThan($overallEndUtc)) {
                    $overallEndUtc = $endAt->utc();
                }

                $preparedItems[] = [
                    'court' => $court,
                    'start_at' => $startAt->utc(),
                    'end_at' => $endAt->utc(),
                    'quote' => $quote,
                ];
            }
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'start_at' => [$e->getMessage()],
            ]);
        }

        $formattedSubtotal = number_format($subtotalFloat, 2, '.', '');
        $discountAmount = '0.00';
        $formattedTotal = $formattedSubtotal;
        $appliedPromotion = null;

        if (! empty($payload['promotion_code'])) {
            $promoCalc = $this->promotionService->validateAndCalculate(
                $venue,
                (string) $payload['promotion_code'],
                $subtotalFloat
            );
            $appliedPromotion = $promoCalc['promotion'];
            $discountAmount = $promoCalc['discount_amount'];
            $formattedTotal = $promoCalc['final_total'];
        }

        $confirmImmediately = (bool) ($payload['confirm_immediately'] ?? false);
        $initialBookingStatus = $confirmImmediately ? 'confirmed' : 'pending_payment';
        $initialReservationStatus = $confirmImmediately ? 'confirmed' : 'held';
        $holdDurationMinutes = max(5, (int) ($venue->hold_duration_minutes ?: 10));
        $holdExpiresAt = $confirmImmediately ? null : now()->addMinutes($holdDurationMinutes);

        $plainPublicToken = Str::random(48);
        $publicTokenHash = hash('sha256', $plainPublicToken);

        try {
            $booking = DB::transaction(function () use (
                $venue,
                $courtIds,
                $preparedItems,
                $overallStartUtc,
                $overallEndUtc,
                $formattedSubtotal,
                $discountAmount,
                $formattedTotal,
                $appliedPromotion,
                $payload,
                $actor,
                $confirmImmediately,
                $initialBookingStatus,
                $initialReservationStatus,
                $holdExpiresAt,
                $publicTokenHash,
            ) {
                // Lock all involved courts in deterministic UUID order to prevent deadlocks
                $sortedCourtIds = $courtIds->sort()->values()->all();
                Court::whereIn('id', $sortedCourtIds)->orderBy('id')->lockForUpdate()->get();

                $bookingCode = $this->generateUniqueBookingCode();

                $createdBooking = Booking::create([
                    'booking_code' => $bookingCode,
                    'public_token_hash' => $publicTokenHash,
                    'user_id' => $actor?->id,
                    'venue_id' => $venue->id,
                    'customer_name' => $payload['customer_name'],
                    'customer_phone' => $payload['customer_phone'],
                    'customer_email' => $payload['customer_email'] ?? null,
                    'start_at' => $overallStartUtc,
                    'end_at' => $overallEndUtc,
                    'status' => $initialBookingStatus,
                    'subtotal' => $formattedSubtotal,
                    'discount_amount' => $discountAmount,
                    'tax_amount' => '0.00',
                    'total_amount' => $formattedTotal,
                    'currency' => 'IDR',
                    'hold_expires_at' => $holdExpiresAt,
                    'notes' => $payload['notes'] ?? null,
                    'policy_snapshot_json' => [
                        'timezone' => $venue->timezone,
                        'hold_duration_minutes' => $venue->hold_duration_minutes,
                        'cancellation_cutoff_hours' => $venue->cancellation_cutoff_hours,
                        'reschedule_cutoff_hours' => $venue->reschedule_cutoff_hours,
                        'promotion_code' => $appliedPromotion?->code,
                    ],
                    'created_by' => $actor?->id,
                ]);

                if ($appliedPromotion) {
                    Promotion::whereKey($appliedPromotion->id)->increment('used_count');
                    DB::table('promotion_redemptions')->insert([
                        'id' => (string) Str::uuid(),
                        'promotion_id' => $appliedPromotion->id,
                        'booking_id' => $createdBooking->id,
                        'user_id' => $actor?->id,
                        'discount_amount' => $discountAmount,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach ($preparedItems as $prep) {
                    /** @var Court $court */
                    $court = $prep['court'];
                    $itemQuote = $prep['quote'];

                    $item = BookingItem::create([
                        'booking_id' => $createdBooking->id,
                        'court_id' => $court->id,
                        'court_name_snapshot' => $court->name,
                        'court_code_snapshot' => $court->code,
                        'start_at' => $prep['start_at'],
                        'end_at' => $prep['end_at'],
                        'duration_minutes' => $itemQuote['duration_minutes'],
                        'unit_price_per_hour' => $itemQuote['unit_price_per_hour'],
                        'total_price' => $itemQuote['total_amount'],
                        'pricing_snapshot_json' => $itemQuote['applied_rules'],
                    ]);

                    CourtReservation::create([
                        'venue_id' => $venue->id,
                        'court_id' => $court->id,
                        'booking_id' => $createdBooking->id,
                        'booking_item_id' => $item->id,
                        'start_at' => $prep['start_at'],
                        'end_at' => $prep['end_at'],
                        'status' => $initialReservationStatus,
                        'hold_expires_at' => $holdExpiresAt,
                    ]);
                }

                BookingStatusHistory::create([
                    'booking_id' => $createdBooking->id,
                    'from_status' => null,
                    'to_status' => $initialBookingStatus,
                    'reason' => $confirmImmediately ? 'Booking manual dibuat dan dikonfirmasi petugas.' : 'Hold slot dibuat, menunggu pembayaran.',
                    'actor_user_id' => $actor?->id,
                    'created_at' => now(),
                ]);

                Payment::create([
                    'booking_id' => $createdBooking->id,
                    'provider' => 'manual',
                    'provider_reference' => 'MNL-'.$createdBooking->booking_code,
                    'payment_method' => 'bank_transfer',
                    'amount' => $createdBooking->total_amount,
                    'currency' => $createdBooking->currency,
                    'status' => $confirmImmediately ? 'paid' : 'unpaid',
                    'idempotency_key' => 'init-'.$createdBooking->id,
                    'paid_at' => $confirmImmediately ? now() : null,
                    'verified_by' => $confirmImmediately ? $actor?->id : null,
                    'verified_at' => $confirmImmediately ? now() : null,
                    'payment_instructions_json' => [
                        'bank_name' => $venue->bank_name ?: 'Bank Mandiri',
                        'bank_account_number' => $venue->bank_account_number ?: '1520037845860',
                        'bank_account_holder' => $venue->bank_account_holder ?: 'NURHASDI NANDAR',
                        'whatsapp_admin' => $venue->phone ?: '082347838252',
                        'booking_code' => $createdBooking->booking_code,
                        'amount' => $createdBooking->total_amount,
                    ],
                ]);

                if ($createdBooking->customer_email) {
                    NotificationLog::create([
                        'user_id' => $actor?->id,
                        'booking_id' => $createdBooking->id,
                        'channel' => 'email',
                        'recipient' => $createdBooking->customer_email,
                        'template_key' => $confirmImmediately ? 'booking_confirmed' : 'booking_hold_created',
                        'status' => 'sent',
                        'payload_json' => [
                            'booking_code' => $createdBooking->booking_code,
                            'total_amount' => $createdBooking->total_amount,
                        ],
                        'sent_at' => now(),
                    ]);
                }

                if ($actor) {
                    AuditLogger::record(
                        action: 'booking.created',
                        entityType: 'booking',
                        entityId: $createdBooking->id,
                        before: null,
                        after: $createdBooking->toArray(),
                        actor: $actor,
                        organizationId: $venue->organization_id,
                        venueId: $venue->id,
                    );
                }

                return $createdBooking->load(['items', 'payments', 'venue:id,name,slug,timezone,phone,bank_name,bank_account_number,bank_account_holder']);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23P01' || str_contains($e->getMessage(), 'court_reservations_no_overlap')) {
                throw ValidationException::withMessages([
                    'start_at' => ['Salah satu slot waktu baru saja dipesan oleh pelanggan lain. Silakan muat ulang jadwal.'],
                ]);
            }

            throw $e;
        }

        return [
            'booking' => $booking,
            'public_token' => $plainPublicToken,
        ];
    }

    /**
     * Transition a booking to a new valid status and synchronize court_reservations.
     */
    public function transitionStatus(
        Booking $booking,
        string $toStatus,
        ?string $reason = null,
        ?User $actor = null,
    ): Booking {
        return DB::transaction(function () use ($booking, $toStatus, $reason, $actor) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $fromStatus = $locked->status;

            try {
                BookingLifecycle::assertTransitionAllowed($fromStatus, $toStatus);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'status' => [$e->getMessage()],
                ]);
            }

            $updates = ['status' => $toStatus];
            if ($toStatus === 'cancelled' && $reason) {
                $updates['cancellation_reason'] = $reason;
            }
            if ($toStatus === 'confirmed') {
                $updates['hold_expires_at'] = null;
            }

            $before = $locked->toArray();
            $locked->update($updates);

            $newResStatus = BookingLifecycle::reservationStatusForBookingStatus($toStatus);
            CourtReservation::where('booking_id', $locked->id)->update([
                'status' => $newResStatus,
                'hold_expires_at' => $toStatus === 'confirmed' ? null : $locked->hold_expires_at,
                'updated_at' => now(),
            ]);

            BookingStatusHistory::create([
                'booking_id' => $locked->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason' => $reason,
                'actor_user_id' => $actor?->id,
                'created_at' => now(),
            ]);

            if ($locked->customer_email && in_array($toStatus, ['confirmed', 'cancelled'], true)) {
                NotificationLog::create([
                    'user_id' => $actor?->id,
                    'booking_id' => $locked->id,
                    'channel' => 'email',
                    'recipient' => $locked->customer_email,
                    'template_key' => "booking_{$toStatus}",
                    'status' => 'sent',
                    'payload_json' => [
                        'booking_code' => $locked->booking_code,
                        'status' => $toStatus,
                    ],
                    'sent_at' => now(),
                ]);
            }

            if ($actor) {
                AuditLogger::record(
                    action: "booking.status.{$toStatus}",
                    entityType: 'booking',
                    entityId: $locked->id,
                    before: $before,
                    after: $locked->fresh()?->toArray(),
                    actor: $actor,
                    organizationId: $locked->venue?->organization_id,
                    venueId: $locked->venue_id,
                );
            }

            return $locked->fresh(['items', 'venue:id,name,slug,timezone', 'statusHistories']);
        });
    }

    /**
     * Atomically reschedule an existing pending_payment or confirmed booking to a new court/time.
     *
     * @param  array{court_id: string, start_at: string, end_at: string, reason?: string|null}  $payload
     */
    public function rescheduleBooking(Booking $booking, array $payload, ?User $actor = null): Booking
    {
        $booking->loadMissing('venue');
        $venue = $booking->venue;
        if (! $venue) {
            throw ValidationException::withMessages([
                'booking' => ['Venue tidak ditemukan.'],
            ]);
        }

        if (! in_array($booking->status, ['pending_payment', 'confirmed'], true)) {
            throw ValidationException::withMessages([
                'booking' => ['Hanya booking berstatus pending_payment atau confirmed yang dapat dijadwalkan ulang.'],
            ]);
        }

        $court = Court::where('venue_id', $venue->id)->findOrFail($payload['court_id']);
        $newStartAt = CarbonImmutable::parse($payload['start_at']);
        $newEndAt = CarbonImmutable::parse($payload['end_at']);

        try {
            $this->availabilityEngine->assertSlotIsBookable($venue, $court, $newStartAt, $newEndAt);
            $newQuote = $this->pricingEngine->quote($venue, $court, $newStartAt, $newEndAt);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'start_at' => [$e->getMessage()],
            ]);
        }

        try {
            return DB::transaction(function () use ($booking, $venue, $court, $newStartAt, $newEndAt, $newQuote, $payload, $actor) {
                $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
                $before = $locked->toArray();

                // Release previous reservations first so moving along the same court doesn't self-conflict
                CourtReservation::where('booking_id', $locked->id)
                    ->whereIn('status', ['held', 'confirmed'])
                    ->update(['status' => 'released', 'updated_at' => now()]);

                BookingItem::where('booking_id', $locked->id)->delete();

                $newItem = BookingItem::create([
                    'booking_id' => $locked->id,
                    'court_id' => $court->id,
                    'court_name_snapshot' => $court->name,
                    'court_code_snapshot' => $court->code,
                    'start_at' => $newStartAt->utc(),
                    'end_at' => $newEndAt->utc(),
                    'duration_minutes' => $newQuote['duration_minutes'],
                    'unit_price_per_hour' => $newQuote['unit_price_per_hour'],
                    'total_price' => $newQuote['total_amount'],
                    'pricing_snapshot_json' => $newQuote['applied_rules'],
                ]);

                $resStatus = BookingLifecycle::reservationStatusForBookingStatus($locked->status);
                CourtReservation::create([
                    'venue_id' => $venue->id,
                    'court_id' => $court->id,
                    'booking_id' => $locked->id,
                    'booking_item_id' => $newItem->id,
                    'start_at' => $newStartAt->utc(),
                    'end_at' => $newEndAt->utc(),
                    'status' => $resStatus,
                    'hold_expires_at' => $locked->hold_expires_at,
                ]);

                $locked->update([
                    'start_at' => $newStartAt->utc(),
                    'end_at' => $newEndAt->utc(),
                ]);

                BookingStatusHistory::create([
                    'booking_id' => $locked->id,
                    'from_status' => $locked->status,
                    'to_status' => $locked->status,
                    'reason' => $payload['reason'] ?? sprintf('Jadwal diubah ke %s (%s - %s).', $court->name, $newStartAt->setTimezone($venue->timezone)->format('Y-m-d H:i'), $newEndAt->setTimezone($venue->timezone)->format('H:i')),
                    'actor_user_id' => $actor?->id,
                    'created_at' => now(),
                ]);

                if ($actor) {
                    AuditLogger::record(
                        action: 'booking.rescheduled',
                        entityType: 'booking',
                        entityId: $locked->id,
                        before: $before,
                        after: $locked->fresh(['items'])?->toArray(),
                        actor: $actor,
                        organizationId: $venue->organization_id,
                        venueId: $venue->id,
                    );
                }

                return $locked->fresh(['items', 'venue:id,name,slug,timezone']);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23P01' || str_contains($e->getMessage(), 'court_reservations_no_overlap')) {
                throw ValidationException::withMessages([
                    'start_at' => ['Slot waktu pengganti sudah terisi oleh booking lain.'],
                ]);
            }

            throw $e;
        }
    }

    /**
     * Expire all pending_payment bookings whose hold_expires_at has passed.
     */
    public function expireOverdueHolds(): int
    {
        $overdueIds = Booking::query()
            ->where('status', 'pending_payment')
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->whereDoesntHave('payments', function ($q) {
                $q->whereIn('status', ['pending', 'paid'])
                    ->whereNotNull('proof_file_key');
            })
            ->pluck('id');

        $expiredCount = 0;

        foreach ($overdueIds as $bookingId) {
            DB::transaction(function () use ($bookingId, &$expiredCount) {
                $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();
                if (! $booking || $booking->status !== 'pending_payment') {
                    return;
                }
                if ($booking->hold_expires_at === null || $booking->hold_expires_at->isFuture()) {
                    return;
                }

                $hasPendingProof = Payment::where('booking_id', $booking->id)
                    ->whereIn('status', ['pending', 'paid'])
                    ->whereNotNull('proof_file_key')
                    ->exists();

                if ($hasPendingProof) {
                    return;
                }

                $booking->update(['status' => 'expired']);

                Payment::where('booking_id', $booking->id)
                    ->where('status', 'unpaid')
                    ->update(['status' => 'expired', 'updated_at' => now()]);

                CourtReservation::where('booking_id', $booking->id)
                    ->where('status', 'held')
                    ->update([
                        'status' => 'expired',
                        'updated_at' => now(),
                    ]);

                BookingStatusHistory::create([
                    'booking_id' => $booking->id,
                    'from_status' => 'pending_payment',
                    'to_status' => 'expired',
                    'reason' => 'Batas waktu pembayaran (hold) telah habis.',
                    'actor_user_id' => null,
                    'created_at' => now(),
                ]);

                $expiredCount++;
            });
        }

        return $expiredCount;
    }

    private function expireHoldsForCourt(string $courtId): void
    {
        $overdueBookingIds = CourtReservation::query()
            ->where('court_id', $courtId)
            ->where('status', 'held')
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->whereNotNull('booking_id')
            ->pluck('booking_id')
            ->unique();

        foreach ($overdueBookingIds as $bookingId) {
            DB::transaction(function () use ($bookingId) {
                $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();
                if (! $booking || $booking->status !== 'pending_payment') {
                    return;
                }
                $hasPendingProof = Payment::where('booking_id', $booking->id)
                    ->whereIn('status', ['pending', 'paid'])
                    ->whereNotNull('proof_file_key')
                    ->exists();
                if ($hasPendingProof) {
                    return;
                }
                $booking->update(['status' => 'expired']);
                Payment::where('booking_id', $booking->id)->where('status', 'unpaid')->update(['status' => 'expired']);
                CourtReservation::where('booking_id', $booking->id)->update(['status' => 'expired']);
                BookingStatusHistory::create([
                    'booking_id' => $booking->id,
                    'from_status' => 'pending_payment',
                    'to_status' => 'expired',
                    'reason' => 'Hold kedaluwarsa saat validasi slot baru.',
                    'actor_user_id' => null,
                    'created_at' => now(),
                ]);
            });
        }
    }

    private function generateUniqueBookingCode(): string
    {
        do {
            $code = 'PM-'.now()->format('ymd').'-'.strtoupper(Str::random(5));
        } while (Booking::where('booking_code', $code)->exists());

        return $code;
    }
}
