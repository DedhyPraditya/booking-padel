<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SpecialDate;
use App\Models\User;
use App\Models\Venue;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminSpecialDateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'venue_id' => ['required', 'uuid', 'exists:venues,id'],
        ]);

        $items = SpecialDate::query()
            ->where('venue_id', $validated['venue_id'])
            ->orderBy('date')
            ->get();

        return response()->json([
            'data' => $items,
            'meta' => [
                'venue_id' => $validated['venue_id'],
                'count' => $items->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'venue_id' => ['required', 'uuid', 'exists:venues,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'is_closed' => ['required', 'boolean'],
            'open_time' => ['nullable', 'date_format:H:i'],
            'close_time' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        if (! $validated['is_closed']) {
            if (empty($validated['open_time']) || empty($validated['close_time']) || $validated['close_time'] <= $validated['open_time']) {
                throw ValidationException::withMessages([
                    'close_time' => ['Untuk tanggal khusus yang tetap buka, jam buka dan jam tutup wajib valid.'],
                ]);
            }
        }

        $venue = Venue::findOrFail($validated['venue_id']);

        $specialDate = DB::transaction(function () use ($venue, $validated, $user, $request) {
            $record = SpecialDate::updateOrCreate(
                [
                    'venue_id' => $venue->id,
                    'date' => $validated['date'],
                ],
                [
                    'is_closed' => $validated['is_closed'],
                    'open_time' => $validated['is_closed'] ? null : $validated['open_time'],
                    'close_time' => $validated['is_closed'] ? null : $validated['close_time'],
                    'note' => $validated['note'] ?? null,
                ]
            );

            AuditLogger::record(
                action: 'special_date.upserted',
                entityType: 'special_date',
                entityId: $record->id,
                before: null,
                after: $record->toArray(),
                actor: $user,
                organizationId: $venue->organization_id,
                venueId: $venue->id,
                request: $request,
            );

            return $record;
        });

        return response()->json([
            'data' => $specialDate,
            'meta' => (object) [],
        ], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $specialDate = SpecialDate::with('venue')->findOrFail($id);

        DB::transaction(function () use ($specialDate, $user, $request) {
            $before = $specialDate->toArray();
            $venue = $specialDate->venue;
            $specialDate->delete();

            AuditLogger::record(
                action: 'special_date.deleted',
                entityType: 'special_date',
                entityId: $before['id'],
                before: $before,
                after: null,
                actor: $user,
                organizationId: $venue?->organization_id,
                venueId: $before['venue_id'],
                request: $request,
            );
        });

        return response()->json([
            'data' => ['deleted' => true],
            'meta' => (object) [],
        ]);
    }
}
