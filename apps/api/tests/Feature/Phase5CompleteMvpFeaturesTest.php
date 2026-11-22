<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\OperatingHour;
use App\Models\Organization;
use App\Models\PricingRule;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserVenueAccess;
use App\Models\Venue;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase5CompleteMvpFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Venue $venue;

    private Court $court1;

    private Court $court2;

    private User $venueAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create([
            'name' => 'PADEL MAKASSAR',
            'slug' => 'padel-makassar',
            'status' => 'active',
        ]);

        $this->venue = Venue::create([
            'organization_id' => $this->org->id,
            'name' => 'PADEL MAKASSAR',
            'slug' => 'padel-makassar',
            'address' => 'Jl. Letjen Hertasning No.2, Makassar',
            'city' => 'Makassar',
            'timezone' => 'Asia/Makassar',
            'phone' => '082347838252',
            'bank_name' => 'Bank Mandiri',
            'bank_account_number' => '1520037845860',
            'bank_account_holder' => 'NURHASDI NANDAR',
            'status' => 'active',
            'slot_interval_minutes' => 60,
            'min_booking_duration_minutes' => 60,
            'max_booking_duration_minutes' => 960,
        ]);

        $this->court1 = Court::create([
            'venue_id' => $this->venue->id,
            'name' => 'Lapangan 1',
            'code' => '001',
            'court_type' => 'semi_indoor',
            'surface_type' => 'panoramic_turf',
            'status' => 'active',
        ]);

        $this->court2 = Court::create([
            'venue_id' => $this->venue->id,
            'name' => 'Lapangan 2',
            'code' => '002',
            'court_type' => 'semi_indoor',
            'surface_type' => 'panoramic_turf',
            'status' => 'active',
        ]);

        for ($day = 0; $day <= 6; $day++) {
            OperatingHour::create([
                'venue_id' => $this->venue->id,
                'day_of_week' => $day,
                'open_time' => '07:00',
                'close_time' => '23:00',
                'is_closed' => false,
            ]);
        }

        PricingRule::create([
            'venue_id' => $this->venue->id,
            'name' => 'Regular Hours',
            'start_time' => '07:00',
            'end_time' => '23:00',
            'price_per_hour' => 250000.00,
            'priority' => 10,
            'is_active' => true,
        ]);

        $adminRole = Role::where('name', 'venue_admin')->firstOrFail();
        $this->venueAdmin = User::factory()->create();
        UserRole::create([
            'user_id' => $this->venueAdmin->id,
            'role_id' => $adminRole->id,
            'organization_id' => $this->org->id,
            'venue_id' => $this->venue->id,
        ]);
        UserVenueAccess::create([
            'user_id' => $this->venueAdmin->id,
            'venue_id' => $this->venue->id,
            'role_id' => $adminRole->id,
        ]);
    }

    public function test_promotion_voucher_applies_discount_to_quote_and_booking_hold(): void
    {
        $this->actingAs($this->venueAdmin, 'sanctum')
            ->postJson('/api/v1/admin/promotions', [
                'venue_id' => $this->venue->id,
                'code' => 'OPENING10',
                'name' => 'Diskon Opening 10%',
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'usage_limit' => 50,
            ])
            ->assertCreated();

        $holdResp = $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => '2026-11-29T09:00:00+08:00',
            'end_at' => '2026-11-29T11:00:00+08:00',
            'customer_name' => 'Pemakai Voucher',
            'customer_phone' => '081233334444',
            'promotion_code' => 'OPENING10',
        ]);

        // 2 hours @ 250k = 500k, 10% discount = 50k, total = 450k
        $holdResp->assertCreated()
            ->assertJsonPath('data.booking.subtotal', '500000.00')
            ->assertJsonPath('data.booking.discount_amount', '50000.00')
            ->assertJsonPath('data.booking.total_amount', '450000.00');
    }

    public function test_admin_can_reschedule_booking_and_release_old_slot_atomically(): void
    {
        $holdResp = $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => '2026-11-29T13:00:00+08:00',
            'end_at' => '2026-11-29T14:00:00+08:00',
            'customer_name' => 'Pelanggan Reschedule',
            'customer_phone' => '081255556666',
        ]);

        $holdResp->assertCreated();
        $bookingId = $holdResp->json('data.booking.id');

        // Reschedule from Court 1 (13:00-14:00) to Court 2 (15:00-16:00)
        $reschedResp = $this->actingAs($this->venueAdmin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$bookingId}/reschedule", [
                'court_id' => $this->court2->id,
                'start_at' => '2026-11-29T15:00:00+08:00',
                'end_at' => '2026-11-29T16:00:00+08:00',
                'reason' => 'Pindah jam sore atas permintaan pelanggan.',
            ]);

        $reschedResp->assertOk()
            ->assertJsonPath('data.items.0.court_id', $this->court2->id);

        // Old slot on Court 1 (13:00-14:00) is now free and bookable by another customer
        $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => '2026-11-29T13:00:00+08:00',
            'end_at' => '2026-11-29T14:00:00+08:00',
            'customer_name' => 'Pelanggan Pengganti',
            'customer_phone' => '081277778888',
        ])->assertCreated();
    }

    public function test_admin_can_list_customers_and_create_venue_staff(): void
    {
        $this->actingAs($this->venueAdmin, 'sanctum')
            ->postJson('/api/v1/admin/staff', [
                'venue_id' => $this->venue->id,
                'name' => 'Kasir Padel Makassar',
                'email' => 'kasir@padelmakassar.id',
                'phone' => '082300009999',
                'password' => 'Password123!',
                'role' => 'finance_staff',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'finance_staff');

        $this->actingAs($this->venueAdmin, 'sanctum')
            ->getJson('/api/v1/admin/customers')
            ->assertOk();
    }
}
