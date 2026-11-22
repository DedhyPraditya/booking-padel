<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BookingItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'booking_id',
        'court_id',
        'court_name_snapshot',
        'court_code_snapshot',
        'start_at',
        'end_at',
        'duration_minutes',
        'unit_price_per_hour',
        'total_price',
        'pricing_snapshot_json',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'duration_minutes' => 'integer',
            'unit_price_per_hour' => 'decimal:2',
            'total_price' => 'decimal:2',
            'pricing_snapshot_json' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(CourtReservation::class);
    }
}
