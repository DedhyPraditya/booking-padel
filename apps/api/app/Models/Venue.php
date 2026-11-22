<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'address',
        'city',
        'timezone',
        'phone',
        'bank_name',
        'bank_account_number',
        'bank_account_holder',
        'description',
        'status',
        'slot_interval_minutes',
        'min_booking_duration_minutes',
        'max_booking_duration_minutes',
        'buffer_minutes',
        'hold_duration_minutes',
        'cancellation_cutoff_hours',
        'reschedule_cutoff_hours',
    ];

    protected function casts(): array
    {
        return [
            'slot_interval_minutes' => 'integer',
            'min_booking_duration_minutes' => 'integer',
            'max_booking_duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'hold_duration_minutes' => 'integer',
            'cancellation_cutoff_hours' => 'integer',
            'reschedule_cutoff_hours' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function courts(): HasMany
    {
        return $this->hasMany(Court::class);
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(OperatingHour::class);
    }

    public function specialDates(): HasMany
    {
        return $this->hasMany(SpecialDate::class);
    }

    public function courtBlocks(): HasMany
    {
        return $this->hasMany(CourtBlock::class);
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }
}
