<?php

use App\Domain\Booking\BookingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('bookings:expire-holds', function (BookingService $bookingService): void {
    $count = $bookingService->expireOverdueHolds();
    $this->info("Expired {$count} overdue booking hold(s).");
})->purpose('Expire overdue booking holds and release court reservations');

Schedule::command('bookings:expire-holds')->everyMinute()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=0')->dailyAt('00:05')->timezone('Asia/Makassar');
