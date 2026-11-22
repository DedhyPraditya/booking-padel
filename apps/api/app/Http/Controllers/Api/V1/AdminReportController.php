<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Court;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReportController extends Controller
{
    public function revenue(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $venueIds = $user->isSuperAdmin()
            ? null
            : $user->accessibleVenueIds();

        $paymentQuery = Payment::query();
        $bookingQuery = Booking::query();

        if ($request->filled('venue_id')) {
            $venueId = (string) $request->query('venue_id');
            if (! $user->canAccessVenue($venueId)) {
                return response()->json(['message' => 'You are not authorized to access this venue.'], 403);
            }
            $paymentQuery->whereHas('booking', fn ($q) => $q->where('venue_id', $venueId));
            $bookingQuery->where('venue_id', $venueId);
        } elseif ($venueIds !== null) {
            $paymentQuery->whereHas('booking', fn ($q) => $q->whereIn('venue_id', $venueIds));
            $bookingQuery->whereIn('venue_id', $venueIds);
        }

        if ($request->filled('from_date')) {
            $from = (string) $request->query('from_date');
            $paymentQuery->whereDate('created_at', '>=', $from);
            $bookingQuery->whereDate('start_at', '>=', $from);
        }

        if ($request->filled('to_date')) {
            $to = (string) $request->query('to_date');
            $paymentQuery->whereDate('created_at', '<=', $to);
            $bookingQuery->whereDate('start_at', '<=', $to);
        }

        $paidTotal = (float) (clone $paymentQuery)->where('status', 'paid')->sum('amount');
        $pendingVerificationTotal = (float) (clone $paymentQuery)->where('status', 'pending')->sum('amount');
        $unpaidTotal = (float) (clone $paymentQuery)->where('status', 'unpaid')->sum('amount');
        $refundedTotal = (float) (clone $paymentQuery)->whereIn('status', ['refunded', 'partially_refunded'])->sum('amount');

        $bookingsByStatus = (clone $bookingQuery)
            ->selectRaw('status, count(*) as total_count')
            ->groupBy('status')
            ->pluck('total_count', 'status');

        return response()->json([
            'data' => [
                'currency' => 'IDR',
                'paid_revenue' => number_format($paidTotal, 2, '.', ''),
                'pending_verification_amount' => number_format($pendingVerificationTotal, 2, '.', ''),
                'unpaid_hold_amount' => number_format($unpaidTotal, 2, '.', ''),
                'refunded_amount' => number_format($refundedTotal, 2, '.', ''),
                'total_bookings' => array_sum($bookingsByStatus->all()),
                'bookings_by_status' => $bookingsByStatus,
            ],
            'meta' => (object) [],
        ]);
    }

    public function utilization(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $courtQuery = Court::query()->orderBy('code');

        if ($request->filled('venue_id')) {
            $venueId = (string) $request->query('venue_id');
            if (! $user->canAccessVenue($venueId)) {
                return response()->json(['message' => 'You are not authorized to access this venue.'], 403);
            }
            $courtQuery->where('venue_id', $venueId);
        } elseif (! $user->isSuperAdmin()) {
            $courtQuery->whereIn('venue_id', $user->accessibleVenueIds());
        }

        $courts = $courtQuery->get();

        $utilization = $courts->map(function (Court $court) use ($request) {
            $itemQuery = BookingItem::query()
                ->where('court_id', $court->id)
                ->whereHas('booking', fn ($q) => $q->whereIn('status', ['confirmed', 'checked_in', 'completed']));

            if ($request->filled('from_date')) {
                $itemQuery->whereDate('start_at', '>=', (string) $request->query('from_date'));
            }
            if ($request->filled('to_date')) {
                $itemQuery->whereDate('start_at', '<=', (string) $request->query('to_date'));
            }

            $bookedMinutes = (int) (clone $itemQuery)->sum('duration_minutes');
            $bookedSessions = (int) (clone $itemQuery)->count();
            $revenue = (float) (clone $itemQuery)->sum('total_price');

            return [
                'court_id' => $court->id,
                'court_code' => $court->code,
                'court_name' => $court->name,
                'court_type' => $court->court_type,
                'confirmed_sessions_count' => $bookedSessions,
                'booked_minutes' => $bookedMinutes,
                'booked_hours' => round($bookedMinutes / 60.0, 2),
                'revenue' => number_format($revenue, 2, '.', ''),
            ];
        })->values();

        return response()->json([
            'data' => $utilization,
            'meta' => [
                'count' => $utilization->count(),
            ],
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = AuditLog::query()
            ->with('actor:id,name,email')
            ->orderByDesc('created_at');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('venue_id', $user->accessibleVenueIds());
        }

        $logs = $query->limit(50)->get();

        return response()->json([
            'data' => $logs,
            'meta' => [
                'count' => $logs->count(),
            ],
        ]);
    }
}
