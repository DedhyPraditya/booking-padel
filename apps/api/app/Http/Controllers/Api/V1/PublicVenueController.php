<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;

class PublicVenueController extends Controller
{
    public function index(): JsonResponse
    {
        $venues = Venue::query()
            ->where('status', 'active')
            ->withCount(['courts' => fn ($q) => $q->where('status', 'active')])
            ->withMin(['pricingRules as starting_price_per_hour' => fn ($q) => $q->where('is_active', true)], 'price_per_hour')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'slug',
                'address',
                'city',
                'timezone',
                'phone',
                'description',
                'slot_interval_minutes',
                'min_booking_duration_minutes',
                'max_booking_duration_minutes',
            ]);

        return response()->json([
            'data' => $venues,
            'meta' => [
                'count' => $venues->count(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $venue = Venue::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->with([
                'courts' => fn ($q) => $q->whereIn('status', ['active', 'maintenance'])->orderBy('code'),
                'operatingHours' => fn ($q) => $q->orderBy('day_of_week'),
                'pricingRules' => fn ($q) => $q->where('is_active', true)->orderByDesc('priority')->orderBy('start_time'),
            ])
            ->firstOrFail([
                'id',
                'name',
                'slug',
                'address',
                'city',
                'timezone',
                'phone',
                'description',
                'slot_interval_minutes',
                'min_booking_duration_minutes',
                'max_booking_duration_minutes',
                'buffer_minutes',
                'hold_duration_minutes',
                'cancellation_cutoff_hours',
                'reschedule_cutoff_hours',
            ]);

        return response()->json([
            'data' => $venue,
            'meta' => (object) [],
        ]);
    }
}
