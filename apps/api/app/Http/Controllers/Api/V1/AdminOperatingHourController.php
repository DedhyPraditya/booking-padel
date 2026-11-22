<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OperatingHour;
use App\Models\User;
use App\Models\Venue;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminOperatingHourController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'venue_id' => ['required', 'uuid', 'exists:venues,id'],
        ]);

        $hours = OperatingHour::query()
            ->where('venue_id', $validated['venue_id'])
            ->orderBy('day_of_week')
            ->get();

        return response()->json([
            'data' => $hours,
            'meta' => [
                'venue_id' => $validated['venue_id'],
                'count' => $hours->count(),
            ],
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'venue_id' => ['required', 'uuid', 'exists:venues,id'],
            'hours' => ['required', 'array', 'min:1', 'max:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'hours.*.open_time' => ['required', 'date_format:H:i'],
            'hours.*.close_time' => ['required', 'date_format:H:i'],
            'hours.*.is_closed' => ['required', 'boolean'],
        ]);

        foreach ($validated['hours'] as $idx => $item) {
            if (! $item['is_closed'] && $item['close_time'] <= $item['open_time']) {
                throw ValidationException::withMessages([
                    "hours.$idx.close_time" => ['Jam tutup harus lebih besar dari jam buka pada hari operasional aktif.'],
                ]);
            }
        }

        $venue = Venue::findOrFail($validated['venue_id']);

        $saved = DB::transaction(function () use ($venue, $validated, $user, $request) {
            $before = OperatingHour::where('venue_id', $venue->id)
                ->orderBy('day_of_week')
                ->get()
                ->toArray();

            foreach ($validated['hours'] as $item) {
                OperatingHour::updateOrCreate(
                    [
                        'venue_id' => $venue->id,
                        'day_of_week' => $item['day_of_week'],
                    ],
                    [
                        'open_time' => $item['open_time'],
                        'close_time' => $item['close_time'],
                        'is_closed' => $item['is_closed'],
                    ]
                );
            }

            $after = OperatingHour::where('venue_id', $venue->id)
                ->orderBy('day_of_week')
                ->get();

            AuditLogger::record(
                action: 'operating_hours.updated',
                entityType: 'venue',
                entityId: $venue->id,
                before: $before,
                after: $after->toArray(),
                actor: $user,
                organizationId: $venue->organization_id,
                venueId: $venue->id,
                request: $request,
            );

            return $after;
        });

        return response()->json([
            'data' => $saved,
            'meta' => [
                'venue_id' => $venue->id,
                'count' => $saved->count(),
            ],
        ]);
    }
}
