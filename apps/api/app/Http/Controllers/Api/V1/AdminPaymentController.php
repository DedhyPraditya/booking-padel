<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payment\PaymentService;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Payment::query()
            ->with([
                'booking.items',
                'booking.venue:id,name,slug,timezone,bank_name,bank_account_number,bank_account_holder',
                'verifier:id,name,email',
                'refunds',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('venue_id')) {
            $venueId = (string) $request->query('venue_id');
            if (! $user->canAccessVenue($venueId)) {
                return response()->json(['message' => 'You are not authorized to access this venue.'], 403);
            }
            $query->whereHas('booking', fn ($q) => $q->where('venue_id', $venueId));
        } elseif (! $user->isSuperAdmin()) {
            $query->whereHas('booking', fn ($q) => $q->whereIn('venue_id', $user->accessibleVenueIds()));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $payments = $query->limit(100)->get();

        return response()->json([
            'data' => $payments,
            'meta' => [
                'count' => $payments->count(),
            ],
        ]);
    }

    public function downloadProof(string $id): StreamedResponse|JsonResponse
    {
        $payment = Payment::findOrFail($id);

        if (empty($payment->proof_file_key) || ! Storage::disk('local')->exists($payment->proof_file_key)) {
            return response()->json([
                'message' => 'File bukti transfer tidak ditemukan.',
            ], 404);
        }

        return Storage::disk('local')->response(
            $payment->proof_file_key,
            $payment->proof_original_name ?: basename($payment->proof_file_key),
            [
                'Content-Type' => $payment->proof_mime_type ?: 'application/octet-stream',
            ]
        );
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $payment = Payment::with('booking.venue')->findOrFail($id);

        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->paymentService->verifyManualPayment(
            payment: $payment,
            decision: $validated['decision'],
            verifier: $user,
            notes: $validated['notes'] ?? null,
        );

        return response()->json([
            'data' => $updated,
            'meta' => (object) [],
        ]);
    }

    public function storeRefund(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'payment_id' => ['required', 'uuid', 'exists:payments,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $payment = Payment::with('booking.venue')->findOrFail($validated['payment_id']);

        if ($payment->booking && ! $user->canAccessVenue($payment->booking->venue_id)) {
            return response()->json(['message' => 'You are not authorized to access this venue.'], 403);
        }

        $refund = $this->paymentService->createRefund(
            payment: $payment,
            amount: (float) $validated['amount'],
            reason: $validated['reason'],
            actor: $user,
        );

        return response()->json([
            'data' => $refund,
            'meta' => (object) [],
        ], 201);
    }
}
