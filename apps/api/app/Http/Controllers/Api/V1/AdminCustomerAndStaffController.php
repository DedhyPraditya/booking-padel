<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserVenueAccess;
use App\Models\Venue;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminCustomerAndStaffController extends Controller
{
    public function customers(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Booking::query()
            ->selectRaw("
                customer_phone,
                MAX(customer_name) as customer_name,
                MAX(customer_email) as customer_email,
                COUNT(*) as total_bookings,
                SUM(CASE WHEN status IN ('confirmed', 'checked_in', 'completed') THEN total_amount ELSE 0 END) as total_spent,
                MAX(start_at) as last_booking_at
            ")
            ->groupBy('customer_phone')
            ->orderByDesc('total_spent');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('venue_id', $user->accessibleVenueIds());
        }

        if ($request->filled('search')) {
            $term = '%'.strtolower(trim((string) $request->query('search'))).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(customer_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(customer_phone) LIKE ?', [$term]);
            });
        }

        $customers = $query->limit(100)->get();

        return response()->json([
            'data' => $customers,
            'meta' => [
                'count' => $customers->count(),
            ],
        ]);
    }

    public function staffIndex(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = User::query()
            ->whereHas('userRoles')
            ->with(['userRoles.role:id,name,description', 'userRoles.venue:id,name'])
            ->orderBy('name');

        if (! $user->isSuperAdmin()) {
            $venueIds = $user->accessibleVenueIds();
            $query->whereHas('userRoles', fn ($q) => $q->whereIn('venue_id', $venueIds));
        }

        $staff = $query->get()->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'is_active' => $u->is_active,
            'roles' => $u->userRoles->map(fn ($ur) => [
                'role' => $ur->role?->name,
                'venue_id' => $ur->venue_id,
                'venue_name' => $ur->venue?->name,
            ]),
        ]);

        return response()->json([
            'data' => $staff,
            'meta' => [
                'count' => $staff->count(),
            ],
        ]);
    }

    public function staffStore(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'venue_id' => ['required', 'uuid', 'exists:venues,id'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'role' => [
                'required',
                'string',
                Rule::in(['venue_admin', 'booking_staff', 'finance_staff', 'operations_staff']),
            ],
        ]);

        $venue = Venue::findOrFail($validated['venue_id']);
        $role = Role::where('name', $validated['role'])->firstOrFail();

        $createdUser = DB::transaction(function () use ($validated, $venue, $role, $actor, $request) {
            $newUser = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'password' => Hash::make($validated['password']),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'is_active' => true,
            ]);

            UserRole::create([
                'user_id' => $newUser->id,
                'role_id' => $role->id,
                'organization_id' => $venue->organization_id,
                'venue_id' => $venue->id,
            ]);

            UserVenueAccess::create([
                'user_id' => $newUser->id,
                'venue_id' => $venue->id,
                'role_id' => $role->id,
            ]);

            AuditLogger::record(
                action: 'staff.created',
                entityType: 'user',
                entityId: $newUser->id,
                before: null,
                after: [
                    'id' => $newUser->id,
                    'name' => $newUser->name,
                    'email' => $newUser->email,
                    'role' => $role->name,
                ],
                actor: $actor,
                organizationId: $venue->organization_id,
                venueId: $venue->id,
                request: $request,
            );

            return $newUser;
        });

        return response()->json([
            'data' => [
                'id' => $createdUser->id,
                'name' => $createdUser->name,
                'email' => $createdUser->email,
                'phone' => $createdUser->phone,
                'is_active' => $createdUser->is_active,
                'role' => $role->name,
            ],
            'meta' => (object) [],
        ], 201);
    }
}
