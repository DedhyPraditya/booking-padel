<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\Venue;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminPricingRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = PricingRule::query()
            ->with(['court:id,venue_id,name,code'])
            ->orderByDesc('priority')
            ->orderBy('start_time');

        if ($request->filled('venue_id')) {
            $venueId = (string) $request->query('venue_id');
            if (! $user->canAccessVenue($venueId)) {
                return response()->json(['message' => 'You are not authorized to access this venue.'], 403);
            }
            $query->where('venue_id', $venueId);
        } elseif (! $user->isSuperAdmin()) {
            $query->whereIn('venue_id', $user->accessibleVenueIds());
        }

        $rules = $query->get();

        return response()->json([
            'data' => $rules,
            'meta' => [
                'count' => $rules->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'venue_id' => ['required', 'uuid', 'exists:venues,id'],
            'court_id' => ['nullable', 'uuid', 'exists:courts,id'],
            'name' => ['required', 'string', 'max:120'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'price_per_hour' => ['required', 'numeric', 'min:0'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validated['end_time'] <= $validated['start_time']) {
            throw ValidationException::withMessages([
                'end_time' => ['Jam selesai tarif harus lebih besar dari jam mulai.'],
            ]);
        }

        $venue = Venue::findOrFail($validated['venue_id']);

        if (! empty($validated['court_id'])) {
            $court = Court::findOrFail($validated['court_id']);
            if ($court->venue_id !== $venue->id) {
                throw ValidationException::withMessages([
                    'court_id' => ['Lapangan tidak berada di venue yang dipilih.'],
                ]);
            }
        }

        $rule = DB::transaction(function () use ($validated, $venue, $user, $request) {
            $created = PricingRule::create($validated);

            AuditLogger::record(
                action: 'pricing_rule.created',
                entityType: 'pricing_rule',
                entityId: $created->id,
                before: null,
                after: $created->toArray(),
                actor: $user,
                organizationId: $venue->organization_id,
                venueId: $venue->id,
                request: $request,
            );

            return $created->load('court:id,venue_id,name,code');
        });

        return response()->json([
            'data' => $rule,
            'meta' => (object) [],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $rule = PricingRule::with('venue')->findOrFail($id);

        $validated = $request->validate([
            'court_id' => ['nullable', 'uuid', 'exists:courts,id'],
            'name' => ['sometimes', 'string', 'max:120'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
            'price_per_hour' => ['sometimes', 'numeric', 'min:0'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $startTime = $validated['start_time'] ?? substr((string) $rule->start_time, 0, 5);
        $endTime = $validated['end_time'] ?? substr((string) $rule->end_time, 0, 5);
        if ($endTime <= $startTime) {
            throw ValidationException::withMessages([
                'end_time' => ['Jam selesai tarif harus lebih besar dari jam mulai.'],
            ]);
        }

        if (! empty($validated['court_id'])) {
            $court = Court::findOrFail($validated['court_id']);
            if ($court->venue_id !== $rule->venue_id) {
                throw ValidationException::withMessages([
                    'court_id' => ['Lapangan tidak berada di venue aturan tarif ini.'],
                ]);
            }
        }

        $updated = DB::transaction(function () use ($rule, $validated, $user, $request) {
            $before = $rule->toArray();
            $rule->update($validated);

            AuditLogger::record(
                action: 'pricing_rule.updated',
                entityType: 'pricing_rule',
                entityId: $rule->id,
                before: $before,
                after: $rule->fresh()?->toArray(),
                actor: $user,
                organizationId: $rule->venue?->organization_id,
                venueId: $rule->venue_id,
                request: $request,
            );

            return $rule->fresh(['court:id,venue_id,name,code']);
        });

        return response()->json([
            'data' => $updated,
            'meta' => (object) [],
        ]);
    }
}
