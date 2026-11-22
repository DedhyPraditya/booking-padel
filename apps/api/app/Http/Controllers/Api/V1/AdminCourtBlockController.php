<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCourtBlockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = CourtBlock::query()
            ->with(['court:id,venue_id,name,code', 'creator:id,name,email'])
            ->orderByDesc('start_at');

        if ($request->filled('venue_id')) {
            $venueId = (string) $request->query('venue_id');
            if (! $user->canAccessVenue($venueId)) {
                return response()->json(['message' => 'You are not authorized to access this venue.'], 403);
            }
            $query->where('venue_id', $venueId);
        } elseif (! $user->isSuperAdmin()) {
            $query->whereIn('venue_id', $user->accessibleVenueIds());
        }

        if ($request->filled('court_id')) {
            $query->where('court_id', (string) $request->query('court_id'));
        }

        $blocks = $query->get();

        return response()->json([
            'data' => $blocks,
            'meta' => [
                'count' => $blocks->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'venue_id' => ['required', 'uuid', 'exists:venues,id'],
            'court_id' => ['required', 'uuid', 'exists:courts,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'reason' => ['required', 'string', 'max:190'],
        ]);

        $court = Court::with('venue')->findOrFail($validated['court_id']);
        if ($court->venue_id !== $validated['venue_id']) {
            throw ValidationException::withMessages([
                'court_id' => ['Lapangan yang dipilih tidak termasuk dalam venue tersebut.'],
            ]);
        }

        $block = DB::transaction(function () use ($validated, $court, $user, $request) {
            $created = CourtBlock::create([
                'venue_id' => $court->venue_id,
                'court_id' => $court->id,
                'start_at' => $validated['start_at'],
                'end_at' => $validated['end_at'],
                'reason' => $validated['reason'],
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            AuditLogger::record(
                action: 'court_block.created',
                entityType: 'court_block',
                entityId: $created->id,
                before: null,
                after: $created->toArray(),
                actor: $user,
                organizationId: $court->venue?->organization_id,
                venueId: $court->venue_id,
                request: $request,
            );

            return $created->load(['court:id,venue_id,name,code']);
        });

        return response()->json([
            'data' => $block,
            'meta' => (object) [],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $block = CourtBlock::with('venue')->findOrFail($id);

        $validated = $request->validate([
            'start_at' => ['sometimes', 'date'],
            'end_at' => ['sometimes', 'date'],
            'reason' => ['sometimes', 'string', 'max:190'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'cancelled'])],
        ]);

        $newStart = isset($validated['start_at']) ? strtotime($validated['start_at']) : $block->start_at->getTimestamp();
        $newEnd = isset($validated['end_at']) ? strtotime($validated['end_at']) : $block->end_at->getTimestamp();

        if ($newEnd <= $newStart) {
            throw ValidationException::withMessages([
                'end_at' => ['Waktu selesai blok jadwal harus setelah waktu mulai.'],
            ]);
        }

        $updated = DB::transaction(function () use ($block, $validated, $user, $request) {
            $before = $block->toArray();
            $block->update($validated);

            AuditLogger::record(
                action: 'court_block.updated',
                entityType: 'court_block',
                entityId: $block->id,
                before: $before,
                after: $block->fresh()?->toArray(),
                actor: $user,
                organizationId: $block->venue?->organization_id,
                venueId: $block->venue_id,
                request: $request,
            );

            return $block->fresh(['court:id,venue_id,name,code']);
        });

        return response()->json([
            'data' => $updated,
            'meta' => (object) [],
        ]);
    }
}
