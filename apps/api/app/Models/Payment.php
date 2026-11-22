<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasUuids;

    protected $fillable = [
        'booking_id',
        'provider',
        'provider_reference',
        'payment_method',
        'amount',
        'currency',
        'status',
        'idempotency_key',
        'proof_file_key',
        'proof_original_name',
        'proof_mime_type',
        'proof_uploaded_at',
        'sender_bank_name',
        'sender_account_holder',
        'verification_notes',
        'verified_by',
        'verified_at',
        'paid_at',
        'payment_instructions_json',
    ];

    protected $hidden = [
        'proof_file_key',
    ];

    protected $appends = [
        'has_proof',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'proof_uploaded_at' => 'datetime',
            'verified_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_instructions_json' => 'array',
        ];
    }

    public function getHasProofAttribute(): bool
    {
        return ! empty($this->proof_file_key);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
