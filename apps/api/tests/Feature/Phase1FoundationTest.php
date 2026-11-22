<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserVenueAccess;
use App\Models\Venue;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase1FoundationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Venue $venueA;

    private Venue $venueB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create([
            'name' => 'Padel Nusantara',
            'slug' => 'padel-nusantara',
            'status' => 'active',
        ]);

        $this->venueA = Venue::create([
            'organization_id' => $this->org->id,
            'name' => 'Padel CPI Makassar',
            'slug' => 'padel-cpi-makassar',
            'address' => 'CPI Makassar',
            'city' => 'Makassar',
            'timezone' => 'Asia/Makassar',
            'phone' => '+628114001',
            'status' => 'active',
        ]);

        $this->venueB = Venue::create([
            'organization_id' => $this->org->id,
            'name' => 'Padel Panakkukang',
            'slug' => 'padel-panakkukang',
            'address' => 'Panakkukang Makassar',
            'city' => 'Makassar',
            'timezone' => 'Asia/Makassar',
            'phone' => '+628114002',
            'status' => 'active',
        ]);
    }

    public function test_public_can_list_and_view_active_venues(): void
    {
        $listResponse = $this->getJson('/api/v1/venues');
        $listResponse->assertOk()
            ->assertJsonCount(2, 'data');

        $detailResponse = $this->getJson('/api/v1/venues/padel-cpi-makassar');
        $detailResponse->assertOk()
            ->assertJsonPath('data.slug', 'padel-cpi-makassar')
            ->assertJsonPath('data.timezone', 'Asia/Makassar');
    }

    public function test_user_can_login_fetch_profile_and_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'staff@padel.local',
            'password' => 'Secret123!',
        ]);

        $role = Role::where('name', 'venue_admin')->firstOrFail();
        UserRole::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $this->org->id,
            'venue_id' => $this->venueA->id,
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@padel.local',
            'password' => 'Secret123!',
        ]);

        $loginResponse->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'expires_at',
                    'user' => ['id', 'name', 'email', 'roles', 'permissions', 'accessible_venue_ids'],
                ],
            ]);

        $token = $loginResponse->json('data.token');
        $this->assertNotEmpty($loginResponse->json('data.expires_at'));

        $meResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me');

        $meResponse->assertOk()
            ->assertJsonPath('data.email', 'staff@padel.local')
            ->assertJsonPath('data.accessible_venue_ids.0', $this->venueA->id);

        $logoutResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertOk()
            ->assertJsonPath('data.logged_out', true);
    }

    public function test_venue_scoped_admin_cannot_access_or_update_another_venue(): void
    {
        $venueAdminA = User::factory()->create();
        $role = Role::where('name', 'venue_admin')->firstOrFail();

        UserRole::create([
            'user_id' => $venueAdminA->id,
            'role_id' => $role->id,
            'organization_id' => $this->org->id,
            'venue_id' => $this->venueA->id,
        ]);

        UserVenueAccess::create([
            'user_id' => $venueAdminA->id,
            'venue_id' => $this->venueA->id,
            'role_id' => $role->id,
        ]);

        // List only returns Venue A
        $indexResponse = $this->actingAs($venueAdminA, 'sanctum')
            ->getJson('/api/v1/admin/venues');

        $indexResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->venueA->id);

        // Accessing Venue B by ID is forbidden (403)
        $showVenueB = $this->actingAs($venueAdminA, 'sanctum')
            ->getJson('/api/v1/admin/venues/'.$this->venueB->id);

        $showVenueB->assertForbidden();

        // Updating Venue B is forbidden (403)
        $updateVenueB = $this->actingAs($venueAdminA, 'sanctum')
            ->patchJson('/api/v1/admin/venues/'.$this->venueB->id, [
                'name' => 'Hacked Venue Name',
            ]);

        $updateVenueB->assertForbidden();

        // Updating own Venue A succeeds and writes an audit log
        $updateVenueA = $this->actingAs($venueAdminA, 'sanctum')
            ->patchJson('/api/v1/admin/venues/'.$this->venueA->id, [
                'hold_duration_minutes' => 15,
            ]);

        $updateVenueA->assertOk()
            ->assertJsonPath('data.hold_duration_minutes', 15);

        $this->assertTrue(
            AuditLog::where('action', 'venue.updated')
                ->where('entity_id', $this->venueA->id)
                ->where('actor_user_id', $venueAdminA->id)
                ->exists()
        );
    }

    public function test_booking_staff_cannot_manage_venue_configuration(): void
    {
        $bookingStaff = User::factory()->create();
        $role = Role::where('name', 'booking_staff')->firstOrFail();

        UserRole::create([
            'user_id' => $bookingStaff->id,
            'role_id' => $role->id,
            'organization_id' => $this->org->id,
            'venue_id' => $this->venueA->id,
        ]);

        // Booking staff has venue.view -> 200 OK
        $this->actingAs($bookingStaff, 'sanctum')
            ->getJson('/api/v1/admin/venues/'.$this->venueA->id)
            ->assertOk();

        // Booking staff does NOT have venue.manage -> 403 Forbidden
        $this->actingAs($bookingStaff, 'sanctum')
            ->patchJson('/api/v1/admin/venues/'.$this->venueA->id, [
                'name' => 'Unauthorized Change',
            ])
            ->assertForbidden();
    }
}
