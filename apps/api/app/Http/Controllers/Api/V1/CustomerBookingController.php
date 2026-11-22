<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Booking\BookingService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerBookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookingService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $bookings = Booking::query()
            ->where('user_id', $user->id)
            ->with(['items', 'venue:id,name,slug,timezone'])
            ->orderByDesc('start_at')
            ->get();

        return response()->json([
            'data' => $bookings,
            'meta' => [
                'count' => $bookings->count(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $booking = Booking::with(['items', 'venue:id,name,slug,timezone', 'statusHistories'])->findOrFail($id);

        if ($booking->user_id !== $user->id) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        return response()->json([
            'data' => $booking,
            'meta' => (object) [],
        ]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $booking = Booking::with('venue')->findOrFail($id);

        if ($booking->user_id !== $user->id) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $cutoffHours = (int) ($booking->venue?->cancellation_cutoff_hours ?? 24);
        if ($booking->status === 'confirmed' && now()->addHours($cutoffHours)->greaterThan($booking->start_at)) {
            return response()->json([
                'message' => sprintf('Pembatalan mandiri hanya dapat dilakukan minimal %d jam sebelum jadwal bermain.', $cutoffHours),
            ], 422);
        }

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
}
