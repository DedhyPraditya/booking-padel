<?php

namespace App\Domain\Availability;

use App\Domain\Pricing\PricingEngine;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\CourtReservation;
use App\Models\OperatingHour;
use App\Models\PricingRule;
use App\Models\SpecialDate;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class AvailabilityEngine
{
    public function __construct(
        private readonly PricingEngine $pricingEngine,
    ) {}

    /**
     * Build availability grid for a venue on a given local date (YYYY-MM-DD) and duration.
     *
     * @return array{
     *     venue_id: string,
     *     venue_name: string,
     *     date: string,
     *     timezone: string,
     *     duration_minutes: int,
     *     slot_interval_minutes: int,
     *     is_closed: bool,
     *     closed_reason: string|null,
     *     courts: array<int, array<string, mixed>>
     * }
     */
    public function getDailyAvailability(Venue $venue, string $date, ?int $durationMinutes = null): array
    {
        $tz = $venue->timezone ?: 'Asia/Makassar';
        $duration = $durationMinutes ?? (int) ($venue->min_booking_duration_minutes ?: 60);
        $interval = max(15, (int) ($venue->slot_interval_minutes ?: 30));
        $buffer = max(0, (int) ($venue->buffer_minutes ?? 0));

        $localDate = CarbonImmutable::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
        $dayOfWeek = $localDate->dayOfWeek;

        $specialDate = SpecialDate::query()
            ->where('venue_id', $venue->id)
            ->where('date', $date)
            ->first();

        $operatingHour = OperatingHour::query()
            ->where('venue_id', $venue->id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        $isClosed = false;
        $closedReason = null;
        $openTimeStr = '07:00';
        $closeTimeStr = '23:00';

        if ($specialDate) {
            if ($specialDate->is_closed) {
                $isClosed = true;
                $closedReason = $specialDate->note ?: 'Venue tutup pada tanggal khusus ini.';
            } else {
                $openTimeStr = substr((string) $specialDate->open_time, 0, 5);
                $closeTimeStr = substr((string) $specialDate->close_time, 0, 5);
            }
        } elseif (! $operatingHour || $operatingHour->is_closed) {
            $isClosed = true;
            $closedReason = 'Venue tutup pada hari ini.';
        } else {
            $openTimeStr = substr((string) $operatingHour->open_time, 0, 5);
            $closeTimeStr = substr((string) $operatingHour->close_time, 0, 5);
        }

        $courts = Court::query()
            ->where('venue_id', $venue->id)
            ->whereIn('status', ['active', 'maintenance'])
            ->orderBy('code')
            ->get();

        if ($isClosed) {
            return [
                'venue_id' => $venue->id,
                'venue_name' => $venue->name,
                'date' => $date,
                'timezone' => $tz,
                'duration_minutes' => $duration,
                'slot_interval_minutes' => $interval,
                'is_closed' => true,
                'closed_reason' => $closedReason,
                'courts' => $courts->map(fn (Court $c) => [
                    'court_id' => $c->id,
                    'court_name' => $c->name,
                    'court_code' => $c->code,
                    'court_type' => $c->court_type,
                    'status' => $c->status,
                    'slots' => [],
                ])->all(),
            ];
        }

        $dayOpen = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$openTimeStr}", $tz);
        $dayClose = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$closeTimeStr}", $tz);

        $activeReservations = CourtReservation::query()
            ->where('venue_id', $venue->id)
            ->whereIn('status', ['held', 'confirmed'])
            ->where(function ($q) {
                $q->where('status', 'confirmed')
                    ->orWhereNull('hold_expires_at')
                    ->orWhere('hold_expires_at', '>', now());
            })
            ->where('start_at', '<', $dayClose->utc())
            ->where('end_at', '>', $dayOpen->utc())
            ->get();

        $activeBlocks = CourtBlock::query()
            ->where('venue_id', $venue->id)
            ->where('status', 'active')
            ->where('start_at', '<', $dayClose->utc())
            ->where('end_at', '>', $dayOpen->utc())
            ->get();

        $pricingRules = PricingRule::query()
            ->where('venue_id', $venue->id)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get();

        $courtsPayload = [];

        foreach ($courts as $court) {
            $courtReservations = $activeReservations->where('court_id', $court->id);
            $courtBlocks = $activeBlocks->where('court_id', $court->id);
            $slots = [];

            $cursor = $dayOpen;
            while ($cursor->addMinutes($duration)->lessThanOrEqualTo($dayClose)) {
                $slotEnd = $cursor->addMinutes($duration);
                $effectiveEndWithBuffer = $slotEnd->addMinutes($buffer);

                $slotStatus = 'available';
                $available = true;

                if ($court->status !== 'active') {
                    $slotStatus = 'maintenance';
                    $available = false;
                } else {
                    foreach ($courtBlocks as $block) {
                        if ($block->start_at->lt($effectiveEndWithBuffer) && $block->end_at->gt($cursor)) {
                            $slotStatus = 'blocked';
                            $available = false;
                            break;
                        }
                    }

                    if ($available) {
                        foreach ($courtReservations as $res) {
                            $resEndWithBuffer = $res->end_at->copy()->addMinutes($buffer);
                            if ($res->start_at->lt($effectiveEndWithBuffer) && $resEndWithBuffer->gt($cursor)) {
                                $slotStatus = $res->status === 'held' ? 'held' : 'booked';
                                $available = false;
                                break;
                            }
                        }
                    }
                }

                $price = null;
                try {
                    $quote = $this->pricingEngine->quote($venue, $court, $cursor, $slotEnd, $pricingRules);
                    $price = (float) $quote['total_amount'];
                } catch (InvalidArgumentException) {
                    $available = false;
                    $slotStatus = 'unpriced';
                }

                $slots[] = [
                    'start_at' => $cursor->toIso8601String(),
                    'end_at' => $slotEnd->toIso8601String(),
                    'start_time' => $cursor->format('H:i'),
                    'end_time' => $slotEnd->format('H:i'),
                    'available' => $available,
                    'status' => $slotStatus,
                    'price' => $price,
                ];

                $cursor = $cursor->addMinutes($interval);
            }

            $courtsPayload[] = [
                'court_id' => $court->id,
                'court_name' => $court->name,
                'court_code' => $court->code,
                'court_type' => $court->court_type,
                'status' => $court->status,
                'slots' => $slots,
            ];
        }

        return [
            'venue_id' => $venue->id,
            'venue_name' => $venue->name,
            'date' => $date,
            'timezone' => $tz,
            'duration_minutes' => $duration,
            'slot_interval_minutes' => $interval,
            'is_closed' => false,
            'closed_reason' => null,
            'courts' => $courtsPayload,
        ];
    }

    /**
     * Validate that a court interval [startAt, endAt) is inside operating hours and has no block/reservation conflict.
     */
    public function assertSlotIsBookable(Venue $venue, Court $court, CarbonImmutable $startAt, CarbonImmutable $endAt): void
    {
        if ($court->venue_id !== $venue->id) {
            throw new InvalidArgumentException('Lapangan tidak berada pada venue yang dipilih.');
        }

        if ($court->status !== 'active') {
            throw new InvalidArgumentException('Lapangan sedang tidak aktif atau dalam perawatan.');
        }

        $tz = $venue->timezone ?: 'Asia/Makassar';
        $localStart = $startAt->setTimezone($tz);
        $localEnd = $endAt->setTimezone($tz);

        if ($localEnd->lessThanOrEqualTo($localStart)) {
            throw new InvalidArgumentException('Waktu selesai harus setelah waktu mulai.');
        }

        if ($localStart->format('Y-m-d') !== $localEnd->format('Y-m-d')) {
            throw new InvalidArgumentException('Sesi booking harus berada pada tanggal operasional yang sama.');
        }

        $durationMinutes = (int) $localStart->diffInMinutes($localEnd);
        $minDuration = (int) ($venue->min_booking_duration_minutes ?: 60);
        $maxDuration = (int) ($venue->max_booking_duration_minutes ?: 180);
        $interval = max(15, (int) ($venue->slot_interval_minutes ?: 30));

        if ($durationMinutes < $minDuration || $durationMinutes > $maxDuration) {
            throw new InvalidArgumentException(
                sprintf('Durasi booking harus antara %d hingga %d menit.', $minDuration, $maxDuration)
            );
        }

        if ($durationMinutes % $interval !== 0) {
            throw new InvalidArgumentException(
                sprintf('Durasi booking harus kelipatan %d menit.', $interval)
            );
        }

        $dateStr = $localStart->format('Y-m-d');
        $specialDate = SpecialDate::where('venue_id', $venue->id)->where('date', $dateStr)->first();
        $operatingHour = OperatingHour::where('venue_id', $venue->id)->where('day_of_week', $localStart->dayOfWeek)->first();

        if ($specialDate) {
            if ($specialDate->is_closed) {
                throw new InvalidArgumentException('Venue tutup pada tanggal tersebut.');
            }
            $openTime = substr((string) $specialDate->open_time, 0, 5);
            $closeTime = substr((string) $specialDate->close_time, 0, 5);
        } elseif (! $operatingHour || $operatingHour->is_closed) {
            throw new InvalidArgumentException('Venue tutup pada hari tersebut.');
        } else {
            $openTime = substr((string) $operatingHour->open_time, 0, 5);
            $closeTime = substr((string) $operatingHour->close_time, 0, 5);
        }

        $dayOpen = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$dateStr} {$openTime}", $tz);
        $dayClose = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$dateStr} {$closeTime}", $tz);

        if ($localStart->lessThan($dayOpen) || $localEnd->greaterThan($dayClose)) {
            throw new InvalidArgumentException(
                sprintf('Jadwal berada di luar jam operasional venue (%s - %s).', $openTime, $closeTime)
            );
        }

        $hasBlock = CourtBlock::query()
            ->where('court_id', $court->id)
            ->where('status', 'active')
            ->where('start_at', '<', $endAt->utc())
            ->where('end_at', '>', $startAt->utc())
            ->exists();

        if ($hasBlock) {
            throw new InvalidArgumentException('Slot waktu sedang diblokir untuk perawatan atau kegiatan internal.');
        }
    }
}
