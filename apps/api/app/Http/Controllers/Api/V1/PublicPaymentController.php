<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payment\PaymentService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'public_token' => ['required', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $hash = hash('sha256', $validated['public_token']);
        $booking = Booking::with('venue')->where('public_token_hash', $hash)->firstOrFail();

        $payment = $this->paymentService->createOrGetManualPayment(
            $booking,
            $validated['idempotency_key'] ?? null
        );

        return response()->json([
            'data' => $payment,
            'meta' => (object) [],
        ]);
    }

    public function uploadProof(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'public_token' => ['required', 'string'],
            'proof_file' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'dimensions:min_width=80,min_height=80,max_width=6000,max_height=6000',
                'max:5120',
            ],
            'sender_bank_name' => ['nullable', 'string', 'max:80'],
            'sender_account_holder' => ['nullable', 'string', 'max:150'],
        ]);

        $hash = hash('sha256', $validated['public_token']);
        $booking = Booking::with('venue')->where('public_token_hash', $hash)->firstOrFail();

        $payment = $this->paymentService->uploadManualProof(
            booking: $booking,
            file: $request->file('proof_file'),
            senderBankName: $validated['sender_bank_name'] ?? null,
            senderAccountHolder: $validated['sender_account_holder'] ?? null,
        );

        return response()->json([
            'data' => $payment,
            'meta' => (object) [],
        ]);
    }
}
