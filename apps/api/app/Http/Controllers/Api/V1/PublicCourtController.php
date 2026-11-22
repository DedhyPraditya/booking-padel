<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PublicCourtController extends Controller
{
    public function index(string $venueIdentifier): JsonResponse
    {
        $venueQuery = Venue::query()->where('status', 'active');

        if (Str::isUuid($venueIdentifier)) {
            $venueQuery->where('id', $venueIdentifier);
        } else {
            $venueQuery->where('slug', $venueIdentifier);
        }

        $venue = $venueQuery->firstOrFail();

        $courts = $venue->courts()
            ->whereIn('status', ['active', 'maintenance'])
            ->orderBy('code')
            ->get([
                'id',
                'venue_id',
                'name',
                'code',
                'court_type',
                'surface_type',
                'description',
                'status',
            ]);

        return response()->json([
            'data' => $courts,
            'meta' => [
                'venue_id' => $venue->id,
                'venue_slug' => $venue->slug,
                'count' => $courts->count(),
            ],
        ]);
    }
}
