<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Kredensial yang diberikan tidak sesuai.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Akun Anda sedang dinonaktifkan.',
            ], 403);
        }

        // Prune expired tokens for this user
        $user->tokens()->where('expires_at', '<=', now())->delete();

        // Expire at the end of the current WITA day (23:59:59 Asia/Makassar), max 24 hours
        $tz = config('app.venue_timezone', env('DEFAULT_VENUE_TIMEZONE', 'Asia/Makassar'));
        $endOfDayWita = CarbonImmutable::now($tz)->endOfDay();
        $max24Hours = CarbonImmutable::now($tz)->addHours(24);
        $expiresAt = $endOfDayWita->lessThanOrEqualTo($max24Hours) ? $endOfDayWita : $max24Hours;

        $newAccessToken = $user->createToken(
            name: $validated['device_name'] ?? 'web-client',
            abilities: ['*'],
            expiresAt: $expiresAt->utc(),
        );

        return response()->json([
            'data' => [
                'token' => $newAccessToken->plainTextToken,
                'expires_at' => $expiresAt->toIso8601String(),
                'user' => $this->formatUser($user),
            ],
            'meta' => (object) [],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->formatUser($user),
            'meta' => (object) [],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'data' => [
                'logged_out' => true,
            ],
            'meta' => (object) [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatUser(User $user): array
    {
        $user->loadMissing(['userRoles.role.permissions', 'venueAccesses.role.permissions']);

        $roles = $user->userRoles->map(fn ($ur) => [
            'role' => $ur->role?->name,
            'organization_id' => $ur->organization_id,
            'venue_id' => $ur->venue_id,
        ])->values();

        $permissions = $user->userRoles
            ->flatMap(fn ($ur) => $ur->role?->permissions?->pluck('name') ?? collect())
            ->merge(
                $user->venueAccesses->flatMap(fn ($va) => $va->role?->permissions?->pluck('name') ?? collect())
            )
            ->unique()
            ->values();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
            'roles' => $roles,
            'permissions' => $permissions,
            'accessible_venue_ids' => $user->accessibleVenueIds(),
        ];
    }
}
