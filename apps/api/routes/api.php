<?php

use App\Http\Controllers\Api\V1\AdminBookingController;
use App\Http\Controllers\Api\V1\AdminCourtBlockController;
use App\Http\Controllers\Api\V1\AdminCourtController;
use App\Http\Controllers\Api\V1\AdminCustomerAndStaffController;
use App\Http\Controllers\Api\V1\AdminOperatingHourController;
use App\Http\Controllers\Api\V1\AdminPaymentController;
use App\Http\Controllers\Api\V1\AdminPricingRuleController;
use App\Http\Controllers\Api\V1\AdminPromotionController;
use App\Http\Controllers\Api\V1\AdminReportController;
use App\Http\Controllers\Api\V1\AdminSpecialDateController;
use App\Http\Controllers\Api\V1\AdminVenueController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerBookingController;
use App\Http\Controllers\Api\V1\PublicAvailabilityController;
use App\Http\Controllers\Api\V1\PublicBookingController;
use App\Http\Controllers\Api\V1\PublicCourtController;
use App\Http\Controllers\Api\V1\PublicPaymentController;
use App\Http\Controllers\Api\V1\PublicVenueController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public endpoints
    Route::get('/venues', [PublicVenueController::class, 'index'])->name('public.venues.index');
    Route::get('/venues/{slug}', [PublicVenueController::class, 'show'])->name('public.venues.show');
    Route::get('/venues/{venue}/courts', [PublicCourtController::class, 'index'])->name('public.venues.courts');

    Route::get('/availability', [PublicAvailabilityController::class, 'index'])->name('public.availability.index');
    Route::post('/price-quotes', [PublicAvailabilityController::class, 'priceQuote'])->name('public.price-quotes.store');
    Route::post('/promotions/validate', [AdminPromotionController::class, 'validatePublic'])
        ->middleware('throttle:20,1')
        ->name('public.promotions.validate');

    Route::post('/booking-holds', [PublicBookingController::class, 'storeHold'])
        ->middleware('throttle:20,1')
        ->name('public.booking-holds.store');
    Route::get('/booking-status/{publicToken}', [PublicBookingController::class, 'showByPublicToken'])
        ->middleware('throttle:30,1')
        ->name('public.booking-status.show');
    Route::post('/booking-lookup', [PublicBookingController::class, 'lookupByCodeAndPhone'])
        ->middleware('throttle:15,1')
        ->name('public.booking-lookup.store');

    Route::post('/payments', [PublicPaymentController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('public.payments.store');
    Route::post('/payment-proofs', [PublicPaymentController::class, 'uploadProof'])
        ->middleware('throttle:15,1')
        ->name('public.payment-proofs.store');

    // Authentication
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    // Authenticated endpoints
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        // Customer self-service endpoints
        Route::get('/me/bookings', [CustomerBookingController::class, 'index'])->name('customer.bookings.index');
        Route::get('/me/bookings/{id}', [CustomerBookingController::class, 'show'])->name('customer.bookings.show');
        Route::post('/me/bookings/{id}/cancel', [CustomerBookingController::class, 'cancel'])->name('customer.bookings.cancel');

        // Admin endpoints (RBAC + Venue Scope enforced)
        Route::prefix('admin')->group(function (): void {
            // Venues
            Route::get('/venues', [AdminVenueController::class, 'index'])
                ->middleware('permission.venue:venue.view')
                ->name('admin.venues.index');
            Route::post('/venues', [AdminVenueController::class, 'store'])
                ->middleware('permission.venue:venue.manage')
                ->name('admin.venues.store');
            Route::get('/venues/{id}', [AdminVenueController::class, 'show'])
                ->middleware('permission.venue:venue.view')
                ->name('admin.venues.show');
            Route::patch('/venues/{id}', [AdminVenueController::class, 'update'])
                ->middleware('permission.venue:venue.manage')
                ->name('admin.venues.update');

            // Courts
            Route::get('/courts', [AdminCourtController::class, 'index'])
                ->middleware('permission.venue:court.view')
                ->name('admin.courts.index');
            Route::post('/courts', [AdminCourtController::class, 'store'])
                ->middleware('permission.venue:court.manage')
                ->name('admin.courts.store');
            Route::get('/courts/{id}', [AdminCourtController::class, 'show'])
                ->middleware('permission.venue:court.view')
                ->name('admin.courts.show');
            Route::patch('/courts/{id}', [AdminCourtController::class, 'update'])
                ->middleware('permission.venue:court.manage')
                ->name('admin.courts.update');

            // Operating Hours
            Route::get('/operating-hours', [AdminOperatingHourController::class, 'index'])
                ->middleware('permission.venue:schedule.view')
                ->name('admin.operating-hours.index');
            Route::post('/operating-hours', [AdminOperatingHourController::class, 'upsert'])
                ->middleware('permission.venue:schedule.manage')
                ->name('admin.operating-hours.upsert');

            // Special Dates
            Route::get('/special-dates', [AdminSpecialDateController::class, 'index'])
                ->middleware('permission.venue:schedule.view')
                ->name('admin.special-dates.index');
            Route::post('/special-dates', [AdminSpecialDateController::class, 'store'])
                ->middleware('permission.venue:schedule.manage')
                ->name('admin.special-dates.store');
            Route::delete('/special-dates/{id}', [AdminSpecialDateController::class, 'destroy'])
                ->middleware('permission.venue:schedule.manage')
                ->name('admin.special-dates.destroy');

            // Court Blocks
            Route::get('/court-blocks', [AdminCourtBlockController::class, 'index'])
                ->middleware('permission.venue:schedule.view')
                ->name('admin.court-blocks.index');
            Route::post('/court-blocks', [AdminCourtBlockController::class, 'store'])
                ->middleware('permission.venue:schedule.manage')
                ->name('admin.court-blocks.store');
            Route::patch('/court-blocks/{id}', [AdminCourtBlockController::class, 'update'])
                ->middleware('permission.venue:schedule.manage')
                ->name('admin.court-blocks.update');

            // Pricing Rules
            Route::get('/pricing-rules', [AdminPricingRuleController::class, 'index'])
                ->middleware('permission.venue:pricing.view')
                ->name('admin.pricing-rules.index');
            Route::post('/pricing-rules', [AdminPricingRuleController::class, 'store'])
                ->middleware('permission.venue:pricing.manage')
                ->name('admin.pricing-rules.store');
            Route::patch('/pricing-rules/{id}', [AdminPricingRuleController::class, 'update'])
                ->middleware('permission.venue:pricing.manage')
                ->name('admin.pricing-rules.update');

            // Promotions & Vouchers
            Route::get('/promotions', [AdminPromotionController::class, 'index'])
                ->middleware('permission.venue:pricing.view')
                ->name('admin.promotions.index');
            Route::post('/promotions', [AdminPromotionController::class, 'store'])
                ->middleware('permission.venue:pricing.manage')
                ->name('admin.promotions.store');
            Route::patch('/promotions/{id}', [AdminPromotionController::class, 'update'])
                ->middleware('permission.venue:pricing.manage')
                ->name('admin.promotions.update');

            // Bookings
            Route::get('/bookings', [AdminBookingController::class, 'index'])
                ->middleware('permission.venue:booking.view')
                ->name('admin.bookings.index');
            Route::post('/bookings', [AdminBookingController::class, 'store'])
                ->middleware('permission.venue:booking.create')
                ->name('admin.bookings.store');
            Route::get('/bookings/{id}', [AdminBookingController::class, 'show'])
                ->middleware('permission.venue:booking.view')
                ->name('admin.bookings.show');
            Route::post('/bookings/{id}/cancel', [AdminBookingController::class, 'cancel'])
                ->middleware('permission.venue:booking.cancel')
                ->name('admin.bookings.cancel');
            Route::post('/bookings/{id}/reschedule', [AdminBookingController::class, 'reschedule'])
                ->middleware('permission.venue:booking.update')
                ->name('admin.bookings.reschedule');
            Route::patch('/bookings/{id}/status', [AdminBookingController::class, 'updateStatus'])
                ->middleware('permission.venue:booking.update')
                ->name('admin.bookings.status');

            // Payments & Refunds
            Route::get('/payments', [AdminPaymentController::class, 'index'])
                ->middleware('permission.venue:payment.view')
                ->name('admin.payments.index');
            Route::get('/payments/{id}/proof', [AdminPaymentController::class, 'downloadProof'])
                ->middleware('permission.venue:payment.view')
                ->name('admin.payments.proof');
            Route::post('/payments/{id}/verify', [AdminPaymentController::class, 'verify'])
                ->middleware('permission.venue:payment.verify')
                ->name('admin.payments.verify');
            Route::post('/refunds', [AdminPaymentController::class, 'storeRefund'])
                ->middleware('permission.venue:payment.refund')
                ->name('admin.refunds.store');

            // Customers & Staff Management
            Route::get('/customers', [AdminCustomerAndStaffController::class, 'customers'])
                ->middleware('permission.venue:customer.view')
                ->name('admin.customers.index');
            Route::get('/staff', [AdminCustomerAndStaffController::class, 'staffIndex'])
                ->middleware('permission.venue:user.manage')
                ->name('admin.staff.index');
            Route::post('/staff', [AdminCustomerAndStaffController::class, 'staffStore'])
                ->middleware('permission.venue:user.manage')
                ->name('admin.staff.store');

            // Reports & Audit Logs
            Route::get('/dashboard-overview', [AdminReportController::class, 'overview'])
                ->middleware('permission.venue:venue.view')
                ->name('admin.reports.overview');
            Route::get('/reports/revenue', [AdminReportController::class, 'revenue'])
                ->middleware('permission.venue:report.view')
                ->name('admin.reports.revenue');
            Route::get('/reports/utilization', [AdminReportController::class, 'utilization'])
                ->middleware('permission.venue:report.view')
                ->name('admin.reports.utilization');
            Route::get('/audit-logs', [AdminReportController::class, 'auditLogs'])
                ->middleware('permission.venue:audit.view')
                ->name('admin.audit-logs.index');
        });
    });
});
