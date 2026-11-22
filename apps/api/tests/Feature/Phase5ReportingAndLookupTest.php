<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\NotificationLog;
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

class Phase5ReportingAndLookupTest extends TestCase
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
            'slot_interval_minutes' => 30,
            'min_booking_duration_minutes' => 60,
            'max_booking_duration_minutes' => 180,
            'buffer_minutes' => 0,
            'hold_duration_minutes' => 10,
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

    public function test_public_booking_lookup_verifies_booking_code_and_phone_and_logs_notifications(): void
    {
        $holdResp = $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => '2026-11-28T09:00:00+08:00',
            'end_at' => '2026-11-28T10:00:00+08:00',
            'customer_name' => 'Nandar Customer',
            'customer_phone' => '082347838252',
            'customer_email' => 'nandar@padelmakassar.id',
        ]);

        $holdResp->assertCreated();
        $bookingCode = $holdResp->json('data.booking.booking_code');

        // Notification recorded for email
        $this->assertTrue(
            NotificationLog::where('recipient', 'nandar@padelmakassar.id')
                ->where('template_key', 'booking_hold_created')
                ->exists()
        );

        // Wrong phone number -> 404
        $this->postJson('/api/v1/booking-lookup', [
            'booking_code' => $bookingCode,
            'customer_phone' => '081100009999',
        ])->assertNotFound();

        // Matching code + phone -> 200 OK with fresh public_token
        $lookupResp = $this->postJson('/api/v1/booking-lookup', [
            'booking_code' => $bookingCode,
            'customer_phone' => '0823-4783-8252',
        ]);

        $lookupResp->assertOk()
            ->assertJsonPath('data.booking.booking_code', $bookingCode);

        $freshToken = $lookupResp->json('data.public_token');
        $this->assertNotEmpty($freshToken);

        $this->getJson('/api/v1/booking-status/'.$freshToken)
            ->assertOk()
            ->assertJsonPath('data.booking_code', $bookingCode);
    }

    public function test_admin_revenue_and_utilization_reports_reconcile_with_transactions(): void
    {
        // Create 1 confirmed booking on Court 1 (120 mins = Rp 500.000)
        $this->actingAs($this->venueAdmin, 'sanctum')
            ->postJson('/api/v1/admin/bookings', [
                'venue_id' => $this->venue->id,
                'court_id' => $this->court1->id,
                'start_at' => '2026-11-28T10:00:00+08:00',
                'end_at' => '2026-11-28T12:00:00+08:00',
                'customer_name' => 'Confirmed Guest',
                'customer_phone' => '081200001111',
                'confirm_immediately' => true,
            ])
            ->assertCreated();

        // Create 1 pending_payment booking on Court 2 (60 mins = Rp 250.000)
        $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court2->id,
            'start_at' => '2026-11-28T14:00:00+08:00',
            'end_at' => '2026-11-28T15:00:00+08:00',
            'customer_name' => 'Pending Guest',
            'customer_phone' => '081200002222',
        ])->assertCreated();

        $revResp = $this->actingAs($this->venueAdmin, 'sanctum')
            ->getJson('/api/v1/admin/reports/revenue');

        $revResp->assertOk()
            ->assertJsonPath('data.paid_revenue', '500000.00')
            ->assertJsonPath('data.unpaid_hold_amount', '250000.00')
            ->assertJsonPath('data.total_bookings', 2);

        $utilResp = $this->actingAs($this->venueAdmin, 'sanctum')
            ->getJson('/api/v1/admin/reports/utilization');

        $utilResp->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.court_code', '001')
            ->assertJsonPath('data.0.confirmed_sessions_count', 1)
            ->assertJsonPath('data.0.booked_minutes', 120)
            ->assertJsonPath('data.0.revenue', '500000.00')
            ->assertJsonPath('data.1.court_code', '002')
            ->assertJsonPath('data.1.confirmed_sessions_count', 0);

        $this->actingAs($this->venueAdmin, 'sanctum')
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk();
    }
}
