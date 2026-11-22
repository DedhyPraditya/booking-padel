<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Booking\BookingService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminBookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookingService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Booking::query()
            ->with(['items', 'venue:id,name,slug,timezone'])
            ->orderByDesc('start_at');

        if ($request->filled('venue_id')) {
            $venueId = (string) $request->query('venue_id');
            if (! $user->canAccessVenue($venueId)) {
                return response()->json(['message' => 'You are not authorized to access this venue.'], 403);
            }
            $query->where('venue_id', $venueId);
        } elseif (! $user->isSuperAdmin()) {
            $query->whereIn('venue_id', $user->accessibleVenueIds());
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('court_id')) {
            $courtId = (string) $request->query('court_id');
            $query->whereHas('items', fn ($q) => $q->where('court_id', $courtId));
        }

        if ($request->filled('search')) {
            $rawSearch = trim((string) $request->query('search'));
            // Support direct QR scan payload format: PADELMAKASSAR|PM-XXXXXX|...
            if (str_contains($rawSearch, '|')) {
                $parts = explode('|', $rawSearch);
                $rawSearch = $parts[1] ?? $rawSearch;
            }
            $term = '%'.strtolower($rawSearch).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(booking_code) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(customer_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(customer_phone) LIKE ?', [$term]);
            });
        }

        if ($request->filled('date')) {
            $date = (string) $request->query('date');
            $query->whereDate('start_at', $date);
        }

        $bookings = $query->limit(100)->get();

        return response()->json([
            'data' => $bookings,
            'meta' => [
                'count' => $bookings->count(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $booking = Booking::with([
            'items',
            'venue:id,name,slug,timezone',
            'statusHistories.actor:id,name,email',
        ])->findOrFail($id);

        return response()->json([
            'data' => $booking,
            'meta' => (object) [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'venue_id' => ['required', 'uuid', 'exists:venues,id'],
            'court_id' => ['required_without:items', 'nullable', 'uuid', 'exists:courts,id'],
            'start_at' => ['required_without:items', 'nullable', 'date'],
            'end_at' => ['required_without:items', 'nullable', 'date', 'after:start_at'],
            'items' => ['required_without:court_id', 'nullable', 'array', 'min:1', 'max:32'],
            'items.*.court_id' => ['required_with:items', 'uuid', 'exists:courts,id'],
            'items.*.start_at' => ['required_with:items', 'date'],
            'items.*.end_at' => ['required_with:items', 'date'],
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:190'],
            'notes' => ['nullable', 'string', 'max:500'],
            'promotion_code' => ['nullable', 'string', 'max:40'],
            'confirm_immediately' => ['sometimes', 'boolean'],
        ]);

        $result = $this->bookingService->createBookingHold($validated, $user);

        return response()->json([
            'data' => $result['booking'],
            'meta' => [
                'public_token' => $result['public_token'],
            ],
        ], 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $booking = Booking::with('venue')->findOrFail($id);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $updated = $this->bookingService->transitionStatus(
            booking: $booking,
            toStatus: 'cancelled',
            reason: $validated['reason'],
            actor: $user,
        );

        return response()->json([
            'data' => $updated,
            'meta' => (object) [],
        ]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $booking = Booking::with('venue')->findOrFail($id);

        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(['confirmed', 'checked_in', 'completed', 'no_show', 'cancelled']),
            ],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $this->bookingService->transitionStatus(
            booking: $booking,
            toStatus: $validated['status'],
            reason: $validated['reason'] ?? 'Perubahan status oleh petugas.',
            actor: $user,
        );

        return response()->json([
            'data' => $updated,
            'meta' => (object) [],
        ]);
    }

    public function reschedule(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $booking = Booking::with('venue')->findOrFail($id);

        $validated = $request->validate([
            'court_id' => ['required', 'uuid', 'exists:courts,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $this->bookingService->rescheduleBooking($booking, $validated, $user);

        return response()->json([
            'data' => $updated,
            'meta' => (object) [],
        ]);
    }
}
