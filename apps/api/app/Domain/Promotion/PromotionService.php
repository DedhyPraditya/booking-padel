<?php

namespace App\Domain\Promotion;

use App\Models\Promotion;
use App\Models\Venue;
use Illuminate\Validation\ValidationException;

class PromotionService
{
    /**
     * Validate a promotion code for a venue and calculate the discount amount.
     *
     * @return array{promotion: Promotion, discount_amount: string, final_total: string}
     */
    public function validateAndCalculate(Venue $venue, string $code, float $subtotal): array
    {
        $normalizedCode = strtoupper(trim($code));

        $promo = Promotion::query()
            ->where('venue_id', $venue->id)
            ->where('code', $normalizedCode)
            ->where('is_active', true)
            ->first();

        if (! $promo) {
            throw ValidationException::withMessages([
                'promotion_code' => ['Kode voucher tidak ditemukan atau sudah tidak aktif.'],
            ]);
        }

        if ($promo->starts_at !== null && $promo->starts_at->isFuture()) {
            throw ValidationException::withMessages([
                'promotion_code' => ['Periode promo untuk kode voucher ini belum dimulai.'],
            ]);
        }

        if ($promo->ends_at !== null && $promo->ends_at->isPast()) {
            throw ValidationException::withMessages([
                'promotion_code' => ['Masa berlaku kode voucher ini telah berakhir.'],
            ]);
        }

        if ($promo->usage_limit !== null && $promo->used_count >= $promo->usage_limit) {
            throw ValidationException::withMessages([
                'promotion_code' => ['Kuota pemakaian kode voucher ini telah habis.'],
            ]);
        }

        if ($promo->discount_type === 'percentage') {
            $pct = min(100.0, max(0.0, (float) $promo->discount_value));
            $discount = round($subtotal * ($pct / 100.0), 2);
        } else {
            $discount = min($subtotal, max(0.0, (float) $promo->discount_value));
        }

        $finalTotal = max(0.0, round($subtotal - $discount, 2));

        return [
            'promotion' => $promo,
            'discount_amount' => number_format($discount, 2, '.', ''),
            'final_total' => number_format($finalTotal, 2, '.', ''),
        ];
    }
}
