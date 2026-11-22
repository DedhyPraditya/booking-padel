<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Venue;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminVenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Venue::query()->orderBy('name');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('id', $user->accessibleVenueIds());
        }

        $venues = $query->get();

        return response()->json([
            'data' => $venues,
            'meta' => [
                'count' => $venues->count(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $venue = Venue::findOrFail($id);

        return response()->json([
            'data' => $venue,
            'meta' => (object) [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:180', 'alpha_dash', 'unique:venues,slug'],
            'address' => ['required', 'string'],
            'city' => ['required', 'string', 'max:100'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'phone' => ['required', 'string', 'max:30'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'slot_interval_minutes' => ['sometimes', 'integer', Rule::in([15, 30, 60])],
            'min_booking_duration_minutes' => ['sometimes', 'integer', 'min:30', 'max:360'],
            'max_booking_duration_minutes' => ['sometimes', 'integer', 'min:60', 'max:960'],
            'buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'hold_duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:60'],
            'cancellation_cutoff_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'reschedule_cutoff_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
        ]);

        if (! $user->isSuperAdmin() && ! $user->hasRole('org_admin', $validated['organization_id'])) {
            return response()->json([
                'message' => 'Only organization or super administrators can create a new venue.',
            ], 403);
        }

        $venue = DB::transaction(function () use ($validated, $user, $request) {
            $created = Venue::create($validated);

            AuditLogger::record(
                action: 'venue.created',
                entityType: 'venue',
                entityId: $created->id,
                before: null,
                after: $created->toArray(),
                actor: $user,
                organizationId: $created->organization_id,
                venueId: $created->id,
                request: $request,
            );

            return $created;
        });

        return response()->json([
            'data' => $venue,
            'meta' => (object) [],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $venue = Venue::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:180', 'alpha_dash', Rule::unique('venues', 'slug')->ignore($venue->id)],
            'address' => ['sometimes', 'string'],
            'city' => ['sometimes', 'string', 'max:100'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'phone' => ['sometimes', 'string', 'max:30'],
            'bank_name' => ['sometimes', 'string', 'max:80'],
            'bank_account_number' => ['sometimes', 'string', 'max:60'],
            'bank_account_holder' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'slot_interval_minutes' => ['sometimes', 'integer', Rule::in([15, 30, 60])],
            'min_booking_duration_minutes' => ['sometimes', 'integer', 'min:30', 'max:360'],
            'max_booking_duration_minutes' => ['sometimes', 'integer', 'min:60', 'max:960'],
            'buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'hold_duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:60'],
            'cancellation_cutoff_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'reschedule_cutoff_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
        ]);

        $updated = DB::transaction(function () use ($venue, $validated, $user, $request) {
            $before = $venue->toArray();
            $venue->update($validated);

            AuditLogger::record(
                action: 'venue.updated',
                entityType: 'venue',
                entityId: $venue->id,
                before: $before,
                after: $venue->fresh()?->toArray(),
                actor: $user,
                organizationId: $venue->organization_id,
                venueId: $venue->id,
                request: $request,
            );

            return $venue->fresh();
        });

        return response()->json([
            'data' => $updated,
            'meta' => (object) [],
        ]);
    }
}
