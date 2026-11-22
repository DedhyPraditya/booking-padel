<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Availability\AvailabilityEngine;
use App\Domain\Booking\BookingService;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Promotion\PromotionService;
use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PublicAvailabilityController extends Controller
{
    public function __construct(
        private readonly AvailabilityEngine $availabilityEngine,
        private readonly PricingEngine $pricingEngine,
        private readonly BookingService $bookingService,
        private readonly PromotionService $promotionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'venue_id' => ['nullable', 'uuid', 'exists:venues,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'duration_minutes' => ['nullable', 'integer', 'min:30', 'max:960'],
        ]);

        $venue = isset($validated['venue_id'])
            ? Venue::where('status', 'active')->findOrFail($validated['venue_id'])
            : Venue::where('status', 'active')->orderBy('name')->firstOrFail();

        $duration = isset($validated['duration_minutes'])
            ? (int) $validated['duration_minutes']
            : (int) ($venue->min_booking_duration_minutes ?: 60);

        $data = $this->availabilityEngine->getDailyAvailability($venue, $validated['date'], $duration);

        return response()->json([
            'data' => $data,
            'meta' => (object) [],
        ]);
    }

    public function priceQuote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'venue_id' => ['required', 'uuid', 'exists:venues,id'],
            'court_id' => ['required_without:items', 'nullable', 'uuid', 'exists:courts,id'],
            'start_at' => ['required_without:items', 'nullable', 'date'],
            'end_at' => ['required_without:items', 'nullable', 'date', 'after:start_at'],
            'items' => ['required_without:court_id', 'nullable', 'array', 'min:1', 'max:32'],
            'items.*.court_id' => ['required_with:items', 'uuid', 'exists:courts,id'],
            'items.*.start_at' => ['required_with:items', 'date'],
            'items.*.end_at' => ['required_with:items', 'date'],
            'promotion_code' => ['nullable', 'string', 'max:40'],
        ]);

        $venue = Venue::where('status', 'active')->findOrFail($validated['venue_id']);

        $rawItems = ! empty($validated['items'])
            ? $validated['items']
            : [[
                'court_id' => $validated['court_id'],
                'start_at' => $validated['start_at'],
                'end_at' => $validated['end_at'],
            ]];

        $mergedItems = $this->bookingService->normalizeAndMergeRequestedItems($rawItems);

        $lineItems = [];
        $totalMinutes = 0;
        $subtotalFloat = 0.0;
        $allAppliedRules = [];
        $overallStart = null;
        $overallEnd = null;

        try {
            foreach ($mergedItems as $item) {
                $court = Court::where('venue_id', $venue->id)->findOrFail($item['court_id']);
                $startAt = CarbonImmutable::parse($item['start_at']);
                $endAt = CarbonImmutable::parse($item['end_at']);

                $this->availabilityEngine->assertSlotIsBookable($venue, $court, $startAt, $endAt);
                $itemQuote = $this->pricingEngine->quote($venue, $court, $startAt, $endAt);

                $totalMinutes += $itemQuote['duration_minutes'];
                $subtotalFloat += (float) $itemQuote['total_amount'];
                $allAppliedRules = array_merge($allAppliedRules, $itemQuote['applied_rules']);

                $localStart = $startAt->setTimezone($venue->timezone);
                $localEnd = $endAt->setTimezone($venue->timezone);

                if ($overallStart === null || $localStart->lessThan($overallStart)) {
                    $overallStart = $localStart;
                }
                if ($overallEnd === null || $localEnd->greaterThan($overallEnd)) {
                    $overallEnd = $localEnd;
                }

                $lineItems[] = [
                    'court_id' => $court->id,
                    'court_name' => $court->name,
                    'court_code' => $court->code,
                    'start_at' => $localStart->toIso8601String(),
                    'end_at' => $localEnd->toIso8601String(),
                    'start_time' => $localStart->format('H:i'),
                    'end_time' => $localEnd->format('H:i'),
                    'duration_minutes' => $itemQuote['duration_minutes'],
                    'unit_price_per_hour' => $itemQuote['unit_price_per_hour'],
                    'total_price' => $itemQuote['total_amount'],
                ];
            }
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'start_at' => [$e->getMessage()],
            ]);
        }

        $formattedSubtotal = number_format($subtotalFloat, 2, '.', '');
        $discountAmount = '0.00';
        $finalTotal = $formattedSubtotal;
        $appliedPromo = null;

        if (! empty($validated['promotion_code'])) {
            $promoResult = $this->promotionService->validateAndCalculate(
                $venue,
                $validated['promotion_code'],
                $subtotalFloat
            );
            $discountAmount = $promoResult['discount_amount'];
            $finalTotal = $promoResult['final_total'];
            $appliedPromo = [
                'code' => $promoResult['promotion']->code,
                'name' => $promoResult['promotion']->name,
                'discount_type' => $promoResult['promotion']->discount_type,
                'discount_value' => $promoResult['promotion']->discount_value,
            ];
        }

        $firstItem = $lineItems[0];

        return response()->json([
            'data' => [
                'venue_id' => $venue->id,
                'court_id' => $firstItem['court_id'],
                'court_name' => count($lineItems) === 1
                    ? $firstItem['court_name']
                    : sprintf('%d Sesi / Lapangan Terpilih', count($lineItems)),
                'court_code' => $firstItem['court_code'],
                'start_at' => $overallStart?->toIso8601String(),
                'end_at' => $overallEnd?->toIso8601String(),
                'timezone' => $venue->timezone,
                'duration_minutes' => $totalMinutes,
                'unit_price_per_hour' => $firstItem['unit_price_per_hour'],
                'subtotal' => $formattedSubtotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => '0.00',
                'total_amount' => $finalTotal,
                'currency' => 'IDR',
                'promotion' => $appliedPromo,
                'items' => $lineItems,
                'applied_rules' => $allAppliedRules,
            ],
            'meta' => (object) [],
        ]);
    }
}
