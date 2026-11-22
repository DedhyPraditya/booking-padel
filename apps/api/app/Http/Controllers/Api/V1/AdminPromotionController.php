<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Promotion\PromotionService;
use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\User;
use App\Models\Venue;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminPromotionController extends Controller
{
    public function __construct(
        private readonly PromotionService $promotionService,
    ) {}

    public function validatePublic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'venue_id' => ['required', 'uuid', 'exists:venues,id'],
            'code' => ['required', 'string', 'max:40'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $venue = Venue::where('status', 'active')->findOrFail($validated['venue_id']);
        $result = $this->promotionService->validateAndCalculate(
            $venue,
            $validated['code'],
            (float) $validated['subtotal']
        );

        return response()->json([
            'data' => [
                'promotion_id' => $result['promotion']->id,
                'code' => $result['promotion']->code,
                'name' => $result['promotion']->name,
                'discount_type' => $result['promotion']->discount_type,
                'discount_value' => $result['promotion']->discount_value,
                'discount_amount' => $result['discount_amount'],
                'final_total' => $result['final_total'],
            ],
            'meta' => (object) [],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Promotion::query()->orderByDesc('created_at');

        if ($request->filled('venue_id')) {
            $venueId = (string) $request->query('venue_id');
            if (! $user->canAccessVenue($venueId)) {
                return response()->json(['message' => 'You are not authorized to access this venue.'], 403);
            }
            $query->where('venue_id', $venueId);
        } elseif (! $user->isSuperAdmin()) {
            $query->whereIn('venue_id', $user->accessibleVenueIds());
        }

        $promotions = $query->get();

        return response()->json([
            'data' => $promotions,
            'meta' => [
                'count' => $promotions->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'venue_id' => ['required', 'uuid', 'exists:venues,id'],
            'code' => [
                'required',
                'string',
                'max:40',
                'alpha_dash',
                Rule::unique('promotions', 'code')->where(fn ($q) => $q->where('venue_id', $request->input('venue_id'))),
            ],
            'name' => ['required', 'string', 'max:120'],
            'discount_type' => ['required', 'string', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $validated['code'] = strtoupper($validated['code']);
        $venue = Venue::findOrFail($validated['venue_id']);

        $promo = DB::transaction(function () use ($validated, $venue, $user, $request) {
            $created = Promotion::create($validated);

            AuditLogger::record(
                action: 'promotion.created',
                entityType: 'promotion',
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
            'data' => $promo,
            'meta' => (object) [],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $promo = Promotion::with('venue')->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'discount_type' => ['sometimes', 'string', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['sometimes', 'numeric', 'min:1'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $updated = DB::transaction(function () use ($promo, $validated, $user, $request) {
            $before = $promo->toArray();
            $promo->update($validated);

            AuditLogger::record(
                action: 'promotion.updated',
                entityType: 'promotion',
                entityId: $promo->id,
                before: $before,
                after: $promo->fresh()?->toArray(),
                actor: $user,
                organizationId: $promo->venue?->organization_id,
                venueId: $promo->venue_id,
                request: $request,
            );

            return $promo->fresh();
        });

        return response()->json([
            'data' => $updated,
            'meta' => (object) [],
        ]);
    }
}
