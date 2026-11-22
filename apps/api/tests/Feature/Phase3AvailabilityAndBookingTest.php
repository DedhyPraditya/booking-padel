<?php

namespace Tests\Feature;

use App\Domain\Booking\BookingService;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtReservation;
use App\Models\OperatingHour;
use App\Models\Organization;
use App\Models\PricingRule;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserVenueAccess;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase3AvailabilityAndBookingTest extends TestCase
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
            'status' => 'active',
            'slot_interval_minutes' => 30,
            'min_booking_duration_minutes' => 60,
            'max_booking_duration_minutes' => 180,
            'buffer_minutes' => 0,
            'hold_duration_minutes' => 10,
            'cancellation_cutoff_hours' => 24,
            'reschedule_cutoff_hours' => 24,
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
            'end_time' => '16:00',
            'price_per_hour' => 250000.00,
            'priority' => 10,
            'is_active' => true,
        ]);

        PricingRule::create([
            'venue_id' => $this->venue->id,
            'name' => 'Peak Hours',
            'start_time' => '16:00',
            'end_time' => '23:00',
            'price_per_hour' => 350000.00,
            'priority' => 20,
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

    public function test_availability_and_price_quote_calculate_regular_and_cross_boundary_peak_rates(): void
    {
        $availResp = $this->getJson('/api/v1/availability?date=2026-11-24&duration_minutes=60');
        $availResp->assertOk()
            ->assertJsonPath('data.venue_id', $this->venue->id)
            ->assertJsonPath('data.is_closed', false)
            ->assertJsonCount(2, 'data.courts');

        // 90-minute booking crossing 15:30 -> 17:00 (30m Regular @ 250k/h = 125k + 60m Peak @ 350k/h = 350k => 475k)
        $quoteResp = $this->postJson('/api/v1/price-quotes', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => '2026-11-24T15:30:00+08:00',
            'end_at' => '2026-11-24T17:00:00+08:00',
        ]);

        $quoteResp->assertOk()
            ->assertJsonPath('data.duration_minutes', 90)
            ->assertJsonPath('data.total_amount', '475000.00');
    }

    public function test_booking_hold_prevents_overlapping_reservations_and_allows_adjacent_half_open_intervals(): void
    {
        // First customer books 09:00 - 10:00 on Court 1
        $firstHold = $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => '2026-11-24T09:00:00+08:00',
            'end_at' => '2026-11-24T10:00:00+08:00',
            'customer_name' => 'Andi Makassar',
            'customer_phone' => '081234567890',
            'customer_email' => 'andi@example.com',
        ]);

        $firstHold->assertCreated()
            ->assertJsonPath('data.booking.status', 'pending_payment')
            ->assertJsonPath('data.booking.total_amount', '250000.00');

        $publicToken = $firstHold->json('data.public_token');

        // Public status lookup via unguessable token works
        $this->getJson('/api/v1/booking-status/'.$publicToken)
            ->assertOk()
            ->assertJsonPath('data.customer_name', 'Andi Makassar');

        // Second customer tries overlapping slot 09:30 - 10:30 on Court 1 -> 422
        $overlapHold = $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => '2026-11-24T09:30:00+08:00',
            'end_at' => '2026-11-24T10:30:00+08:00',
            'customer_name' => 'Budi Overlap',
            'customer_phone' => '081299998888',
        ]);

        $overlapHold->assertUnprocessable()
            ->assertJsonValidationErrors(['start_at']);

        // Adjacent half-open interval 10:00 - 11:00 on Court 1 MUST succeed
        $adjacentHold = $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => '2026-11-24T10:00:00+08:00',
            'end_at' => '2026-11-24T11:00:00+08:00',
            'customer_name' => 'Citra Adjacent',
            'customer_phone' => '081277776666',
        ]);

        $adjacentHold->assertCreated();

        // Direct database-level exclusion constraint test on court_reservations
        $this->expectException(QueryException::class);
        CourtReservation::create([
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => CarbonImmutable::parse('2026-11-24T09:15:00+08:00')->utc(),
            'end_at' => CarbonImmutable::parse('2026-11-24T09:45:00+08:00')->utc(),
            'status' => 'confirmed',
        ]);
    }

    public function test_expired_hold_releases_slot_and_allows_new_booking(): void
    {
        $holdResp = $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => '2026-11-24T18:00:00+08:00',
            'end_at' => '2026-11-24T19:00:00+08:00',
            'customer_name' => 'Pelanggan Hold',
            'customer_phone' => '081122334455',
        ]);

        $holdResp->assertCreated();
        $bookingId = $holdResp->json('data.booking.id');

        // Fast-forward hold_expires_at into the past
        Booking::whereKey($bookingId)->update(['hold_expires_at' => now()->subMinute()]);
        CourtReservation::where('booking_id', $bookingId)->update(['hold_expires_at' => now()->subMinute()]);

        $expiredCount = app(BookingService::class)->expireOverdueHolds();
        $this->assertSame(1, $expiredCount);
        $this->assertSame('expired', Booking::findOrFail($bookingId)->status);
        $this->assertSame('expired', CourtReservation::where('booking_id', $bookingId)->value('status'));

        // Same slot can now be booked again
        $newHold = $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => '2026-11-24T18:00:00+08:00',
            'end_at' => '2026-11-24T19:00:00+08:00',
            'customer_name' => 'Pelanggan Baru',
            'customer_phone' => '081166778899',
        ]);

        $newHold->assertCreated()
            ->assertJsonPath('data.booking.total_amount', '350000.00');
    }

    public function test_admin_can_create_manual_booking_and_transition_lifecycle_states(): void
    {
        $manualResp = $this->actingAs($this->venueAdmin, 'sanctum')
            ->postJson('/api/v1/admin/bookings', [
                'venue_id' => $this->venue->id,
                'court_id' => $this->court2->id,
                'start_at' => '2026-11-24T20:00:00+08:00',
                'end_at' => '2026-11-24T21:00:00+08:00',
                'customer_name' => 'Walk-in Customer',
                'customer_phone' => '082300001111',
                'confirm_immediately' => true,
            ]);

        $manualResp->assertCreated()
            ->assertJsonPath('data.status', 'confirmed');

        $bookingId = $manualResp->json('data.id');

        // Transition confirmed -> checked_in -> completed
        $this->actingAs($this->venueAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/bookings/{$bookingId}/status", [
                'status' => 'checked_in',
                'reason' => 'Pelanggan hadir di lokasi.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'checked_in');

        $this->actingAs($this->venueAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/bookings/{$bookingId}/status", [
                'status' => 'completed',
                'reason' => 'Sesi selesai.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // Forbidden transition completed -> cancelled -> 422
        $this->actingAs($this->venueAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/bookings/{$bookingId}/status", [
                'status' => 'cancelled',
            ])
            ->assertUnprocessable();
    }

    public function test_multi_hour_and_multi_court_tournament_booking_merges_and_reserves_atomically(): void
    {
        $this->venue->update(['max_booking_duration_minutes' => 960]);

        // Select 3 consecutive hours on Court 1 (08:00-11:00) AND 2 consecutive hours on Court 2 (08:00-10:00)
        $itemsPayload = [
            [
                'court_id' => $this->court1->id,
                'start_at' => '2026-11-25T08:00:00+08:00',
                'end_at' => '2026-11-25T09:00:00+08:00',
            ],
            [
                'court_id' => $this->court1->id,
                'start_at' => '2026-11-25T09:00:00+08:00',
                'end_at' => '2026-11-25T10:00:00+08:00',
            ],
            [
                'court_id' => $this->court1->id,
                'start_at' => '2026-11-25T10:00:00+08:00',
                'end_at' => '2026-11-25T11:00:00+08:00',
            ],
            [
                'court_id' => $this->court2->id,
                'start_at' => '2026-11-25T08:00:00+08:00',
                'end_at' => '2026-11-25T09:00:00+08:00',
            ],
            [
                'court_id' => $this->court2->id,
                'start_at' => '2026-11-25T09:00:00+08:00',
                'end_at' => '2026-11-25T10:00:00+08:00',
            ],
        ];

        // 5 total hours @ Rp 250.000/h = Rp 1.250.000, merged into 2 BookingItems (1 per court)
        $quoteResp = $this->postJson('/api/v1/price-quotes', [
            'venue_id' => $this->venue->id,
            'items' => $itemsPayload,
        ]);

        $quoteResp->assertOk()
            ->assertJsonPath('data.duration_minutes', 300)
            ->assertJsonPath('data.total_amount', '1250000.00')
            ->assertJsonCount(2, 'data.items');

        $holdResp = $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'items' => $itemsPayload,
            'customer_name' => 'Panitia Turnamen Makassar',
            'customer_phone' => '082347838252',
            'notes' => 'Turnamen Komunitas Padel Makassar',
        ]);

        $holdResp->assertCreated()
            ->assertJsonPath('data.booking.total_amount', '1250000.00')
            ->assertJsonCount(2, 'data.booking.items');

        $this->assertSame(
            2,
            CourtReservation::where('booking_id', $holdResp->json('data.booking.id'))
                ->where('status', 'held')
                ->count()
        );
    }
}
