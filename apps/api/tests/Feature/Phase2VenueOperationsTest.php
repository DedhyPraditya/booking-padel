<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Court;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserVenueAccess;
use App\Models\Venue;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2VenueOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Venue $venueA;

    private Venue $venueB;

    private User $venueAdminA;

    private User $bookingStaffA;

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

        $venueAdminRole = Role::where('name', 'venue_admin')->firstOrFail();
        $this->venueAdminA = User::factory()->create();
        UserRole::create([
            'user_id' => $this->venueAdminA->id,
            'role_id' => $venueAdminRole->id,
            'organization_id' => $this->org->id,
            'venue_id' => $this->venueA->id,
        ]);
        UserVenueAccess::create([
            'user_id' => $this->venueAdminA->id,
            'venue_id' => $this->venueA->id,
            'role_id' => $venueAdminRole->id,
        ]);

        $bookingStaffRole = Role::where('name', 'booking_staff')->firstOrFail();
        $this->bookingStaffA = User::factory()->create();
        UserRole::create([
            'user_id' => $this->bookingStaffA->id,
            'role_id' => $bookingStaffRole->id,
            'organization_id' => $this->org->id,
            'venue_id' => $this->venueA->id,
        ]);
    }

    public function test_venue_admin_can_create_and_update_courts_with_unique_code_per_venue(): void
    {
        $createResponse = $this->actingAs($this->venueAdminA, 'sanctum')
            ->postJson('/api/v1/admin/courts', [
                'venue_id' => $this->venueA->id,
                'name' => 'Court 1 Panoramic',
                'code' => 'C1',
                'court_type' => 'indoor',
                'surface_type' => 'mondo_supercourt',
                'status' => 'active',
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.code', 'C1');

        $courtId = $createResponse->json('data.id');

        // Duplicate code in the same venue must fail with 422
        $this->actingAs($this->venueAdminA, 'sanctum')
            ->postJson('/api/v1/admin/courts', [
                'venue_id' => $this->venueA->id,
                'name' => 'Court 1 Duplicate',
                'code' => 'C1',
                'court_type' => 'indoor',
            ])
            ->assertUnprocessable();

        // Creating court in Venue B by Venue A admin must be forbidden (403)
        $this->actingAs($this->venueAdminA, 'sanctum')
            ->postJson('/api/v1/admin/courts', [
                'venue_id' => $this->venueB->id,
                'name' => 'Court Venue B',
                'code' => 'C1',
                'court_type' => 'outdoor',
            ])
            ->assertForbidden();

        // Public court listing returns active/maintenance courts for Venue A
        $this->getJson('/api/v1/venues/'.$this->venueA->slug.'/courts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $courtId);
    }

    public function test_venue_admin_cannot_update_court_belonging_to_another_venue(): void
    {
        $courtB = Court::create([
            'venue_id' => $this->venueB->id,
            'name' => 'Court B1',
            'code' => 'B1',
            'court_type' => 'outdoor',
            'surface_type' => 'turf',
            'status' => 'active',
        ]);

        $this->actingAs($this->venueAdminA, 'sanctum')
            ->patchJson('/api/v1/admin/courts/'.$courtB->id, [
                'name' => 'Unauthorized Rename',
            ])
            ->assertForbidden();
    }

    public function test_operating_hours_and_special_dates_validation_and_persistence(): void
    {
        // Invalid hours (close_time <= open_time when open) -> 422
        $this->actingAs($this->venueAdminA, 'sanctum')
            ->postJson('/api/v1/admin/operating-hours', [
                'venue_id' => $this->venueA->id,
                'hours' => [
                    [
                        'day_of_week' => 1,
                        'open_time' => '22:00',
                        'close_time' => '08:00',
                        'is_closed' => false,
                    ],
                ],
            ])
            ->assertUnprocessable();

        // Valid hours upsert
        $this->actingAs($this->venueAdminA, 'sanctum')
            ->postJson('/api/v1/admin/operating-hours', [
                'venue_id' => $this->venueA->id,
                'hours' => [
                    [
                        'day_of_week' => 1,
                        'open_time' => '07:00',
                        'close_time' => '23:00',
                        'is_closed' => false,
                    ],
                    [
                        'day_of_week' => 2,
                        'open_time' => '07:00',
                        'close_time' => '23:00',
                        'is_closed' => false,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Special date closure
        $specialDateResp = $this->actingAs($this->venueAdminA, 'sanctum')
            ->postJson('/api/v1/admin/special-dates', [
                'venue_id' => $this->venueA->id,
                'date' => '2026-12-25',
                'is_closed' => true,
                'note' => 'Libur Hari Raya',
            ]);

        $specialDateResp->assertCreated()
            ->assertJsonPath('data.is_closed', true);

        $specialDateId = $specialDateResp->json('data.id');

        $this->actingAs($this->venueAdminA, 'sanctum')
            ->deleteJson('/api/v1/admin/special-dates/'.$specialDateId)
            ->assertOk();
    }

    public function test_pricing_rules_and_court_blocks_enforce_permissions_and_audit_logs(): void
    {
        $courtA = Court::create([
            'venue_id' => $this->venueA->id,
            'name' => 'Court A1',
            'code' => 'A1',
            'court_type' => 'indoor',
            'surface_type' => 'turf',
            'status' => 'active',
        ]);

        // Booking staff does NOT have pricing.manage -> 403
        $this->actingAs($this->bookingStaffA, 'sanctum')
            ->postJson('/api/v1/admin/pricing-rules', [
                'venue_id' => $this->venueA->id,
                'name' => 'Unauthorized Rule',
                'start_time' => '08:00',
                'end_time' => '16:00',
                'price_per_hour' => 200000,
            ])
            ->assertForbidden();

        // Venue admin creates pricing rule with decimal amount
        $pricingResp = $this->actingAs($this->venueAdminA, 'sanctum')
            ->postJson('/api/v1/admin/pricing-rules', [
                'venue_id' => $this->venueA->id,
                'court_id' => $courtA->id,
                'name' => 'Peak Hour Court A1',
                'day_of_week' => 6,
                'start_time' => '16:00',
                'end_time' => '23:00',
                'price_per_hour' => 375000.00,
                'priority' => 30,
                'is_active' => true,
            ]);

        $pricingResp->assertCreated()
            ->assertJsonPath('data.price_per_hour', '375000.00');

        // Court block creation
        $blockResp = $this->actingAs($this->venueAdminA, 'sanctum')
            ->postJson('/api/v1/admin/court-blocks', [
                'venue_id' => $this->venueA->id,
                'court_id' => $courtA->id,
                'start_at' => '2026-11-25T10:00:00+08:00',
                'end_at' => '2026-11-25T12:00:00+08:00',
                'reason' => 'Perawatan lampu lapangan',
            ]);

        $blockResp->assertCreated()
            ->assertJsonPath('data.status', 'active');

        $this->assertTrue(
            AuditLog::where('action', 'pricing_rule.created')
                ->where('venue_id', $this->venueA->id)
                ->exists()
        );

        $this->assertTrue(
            AuditLog::where('action', 'court_block.created')
                ->where('venue_id', $this->venueA->id)
                ->exists()
        );
    }
}
