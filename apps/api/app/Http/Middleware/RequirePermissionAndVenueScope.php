<?php

namespace App\Http\Middleware;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\Payment;
use App\Models\PricingRule;
use App\Models\Promotion;
use App\Models\SpecialDate;
use App\Models\Venue;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermissionAndVenueScope
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $venueId = $this->resolveTargetVenueId($request);
        $organizationId = $request->input('organization_id');

        if ($venueId !== null && ! $user->canAccessVenue($venueId)) {
            return response()->json([
                'message' => 'You are not authorized to access this venue.',
            ], 403);
        }

        if (! $user->hasPermission($permission, $venueId, is_string($organizationId) ? $organizationId : null)) {
            return response()->json([
                'message' => 'This action is unauthorized.',
            ], 403);
        }

        return $next($request);
    }

    private function resolveTargetVenueId(Request $request): ?string
    {
        $venueParam = $request->route('venue');
        if ($venueParam instanceof Venue) {
            return $venueParam->id;
        }
        if (is_string($venueParam)) {
            return $venueParam;
        }

        if (is_string($request->input('venue_id'))) {
            return $request->input('venue_id');
        }

        if (is_string($request->query('venue_id'))) {
            return $request->query('venue_id');
        }

        $routeId = $request->route('id');
        if (! is_string($routeId)) {
            return null;
        }

        if ($request->routeIs('admin.venues.*')) {
            return $routeId;
        }

        if ($request->routeIs('admin.courts.*')) {
            return Court::whereKey($routeId)->value('venue_id');
        }

        if ($request->routeIs('admin.special-dates.*')) {
            return SpecialDate::whereKey($routeId)->value('venue_id');
        }

        if ($request->routeIs('admin.court-blocks.*')) {
            return CourtBlock::whereKey($routeId)->value('venue_id');
        }

        if ($request->routeIs('admin.pricing-rules.*')) {
            return PricingRule::whereKey($routeId)->value('venue_id');
        }

        if ($request->routeIs('admin.promotions.*')) {
            return Promotion::whereKey($routeId)->value('venue_id');
        }

        if ($request->routeIs('admin.bookings.*')) {
            return Booking::whereKey($routeId)->value('venue_id');
        }

        if ($request->routeIs('admin.payments.*')) {
            $bookingId = Payment::whereKey($routeId)->value('booking_id');

            return $bookingId ? Booking::whereKey($bookingId)->value('venue_id') : null;
        }

        return null;
    }
}
