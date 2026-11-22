<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRule extends Model
{
    use HasUuids;

    protected $fillable = [
        'venue_id',
        'court_id',
        'name',
        'day_of_week',
        'start_time',
        'end_time',
        'price_per_hour',
        'valid_from',
        'valid_until',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'price_per_hour' => 'decimal:2',
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }
}
