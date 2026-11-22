<?php

namespace App\Jobs;

use App\Domain\Booking\BookingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireBookingHoldsJob implements ShouldQueue
{
    use Queueable;

    public function handle(BookingService $bookingService): void
    {
        $bookingService->expireOverdueHolds();
    }
}
