<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Booking\BookingService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicBookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookingService,
    ) {}

    public function storeHold(Request $request): JsonResponse
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
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:190'],
            'notes' => ['nullable', 'string', 'max:500'],
            'promotion_code' => ['nullable', 'string', 'max:40'],
        ]);

        $actor = $request->user('sanctum');

        $result = $this->bookingService->createBookingHold($validated, $actor);

        return response()->json([
            'data' => [
                'booking' => $result['booking'],
                'public_token' => $result['public_token'],
            ],
            'meta' => (object) [],
        ], 201);
    }

    public function showByPublicToken(string $publicToken): JsonResponse
    {
        $hash = hash('sha256', $publicToken);

        $booking = Booking::query()
            ->where('public_token_hash', $hash)
            ->with([
                'items',
                'payments' => fn ($q) => $q->latest(),
                'venue:id,name,slug,address,city,timezone,phone,bank_name,bank_account_number,bank_account_holder',
            ])
            ->firstOrFail();

        return response()->json([
            'data' => $this->formatPublicBooking($booking),
            'meta' => (object) [],
        ]);
    }

    public function lookupByCodeAndPhone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_code' => ['required', 'string', 'max:40'],
            'customer_phone' => ['required', 'string', 'max:30'],
        ]);

        $normalizedInputPhone = preg_replace('/[^0-9]/', '', $validated['customer_phone']) ?: '';

        $booking = Booking::query()
            ->where('booking_code', strtoupper(trim($validated['booking_code'])))
            ->with([
                'items',
                'payments' => fn ($q) => $q->latest(),
                'venue:id,name,slug,address,city,timezone,phone,bank_name,bank_account_number,bank_account_holder',
            ])
            ->first();

        $storedPhone = $booking ? (preg_replace('/[^0-9]/', '', $booking->customer_phone) ?: '') : '';

        if (! $booking || $normalizedInputPhone === '' || $storedPhone !== $normalizedInputPhone) {
            return response()->json([
                'message' => 'Data booking dengan kombinasi kode dan nomor telepon tersebut tidak ditemukan.',
            ], 404);
        }

        // Rotate a fresh public token so the verified customer can view status & upload proof
        $freshToken = Str::random(48);
        $booking->update([
            'public_token_hash' => hash('sha256', $freshToken),
        ]);

        return response()->json([
            'data' => [
                'booking' => $this->formatPublicBooking($booking),
                'public_token' => $freshToken,
            ],
            'meta' => (object) [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPublicBooking(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'status' => $booking->status,
            'customer_name' => $booking->customer_name,
            'start_at' => $booking->start_at?->toIso8601String(),
            'end_at' => $booking->end_at?->toIso8601String(),
            'subtotal' => $booking->subtotal,
            'discount_amount' => $booking->discount_amount,
            'tax_amount' => $booking->tax_amount,
            'total_amount' => $booking->total_amount,
            'currency' => $booking->currency,
            'hold_expires_at' => $booking->hold_expires_at?->toIso8601String(),
            'venue' => $booking->venue,
            'items' => $booking->items,
            'payments' => $booking->payments,
        ];
    }
}
