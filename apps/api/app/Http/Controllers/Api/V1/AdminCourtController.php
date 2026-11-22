<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\User;
use App\Models\Venue;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminCourtController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Court::query()->with('venue:id,name,slug')->orderBy('code');

        if ($request->filled('venue_id')) {
            $venueId = (string) $request->query('venue_id');
            if (! $user->canAccessVenue($venueId)) {
                return response()->json(['message' => 'You are not authorized to access this venue.'], 403);
            }
            $query->where('venue_id', $venueId);
        } elseif (! $user->isSuperAdmin()) {
            $query->whereIn('venue_id', $user->accessibleVenueIds());
        }

        $courts = $query->get();

        return response()->json([
            'data' => $courts,
            'meta' => [
                'count' => $courts->count(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $court = Court::with('venue:id,name,slug')->findOrFail($id);

        return response()->json([
            'data' => $court,
            'meta' => (object) [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'venue_id' => ['required', 'uuid', 'exists:venues,id'],
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:30',
                'alpha_dash',
                Rule::unique('courts', 'code')->where(fn ($q) => $q->where('venue_id', $request->input('venue_id'))),
            ],
            'court_type' => ['required', 'string', Rule::in(['indoor', 'semi_indoor', 'outdoor'])],
            'surface_type' => ['sometimes', 'string', 'max:60'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'maintenance'])],
        ]);

        $venue = Venue::findOrFail($validated['venue_id']);

        $court = DB::transaction(function () use ($validated, $venue, $user, $request) {
            $created = Court::create($validated);

            AuditLogger::record(
                action: 'court.created',
                entityType: 'court',
                entityId: $created->id,
                before: null,
                after: $created->toArray(),
                actor: $user,
                organizationId: $venue->organization_id,
                venueId: $venue->id,
                request: $request,
            );

            return $created;
        });

        return response()->json([
            'data' => $court,
            'meta' => (object) [],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $court = Court::with('venue')->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'code' => [
                'sometimes',
                'string',
                'max:30',
                'alpha_dash',
                Rule::unique('courts', 'code')
                    ->where(fn ($q) => $q->where('venue_id', $court->venue_id))
                    ->ignore($court->id),
            ],
            'court_type' => ['sometimes', 'string', Rule::in(['indoor', 'semi_indoor', 'outdoor'])],
            'surface_type' => ['sometimes', 'string', 'max:60'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'maintenance'])],
        ]);

        $updated = DB::transaction(function () use ($court, $validated, $user, $request) {
            $before = $court->toArray();
            $court->update($validated);

            AuditLogger::record(
                action: 'court.updated',
                entityType: 'court',
                entityId: $court->id,
                before: $before,
                after: $court->fresh()?->toArray(),
                actor: $user,
                organizationId: $court->venue?->organization_id,
                venueId: $court->venue_id,
                request: $request,
            );

            return $court->fresh();
        });

        return response()->json([
            'data' => $updated,
            'meta' => (object) [],
        ]);
    }
}
