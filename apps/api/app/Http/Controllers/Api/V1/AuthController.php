<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
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

        $token = $user->createToken($validated['device_name'] ?? 'web-client')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
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
