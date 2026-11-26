<?php

namespace Tests\Feature;

use App\Domain\Booking\BookingService;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Court;
use App\Models\OperatingHour;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PricingRule;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserVenueAccess;
use App\Models\Venue;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase4ManualPaymentAndVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Venue $venue;

    private Court $court1;

    private User $venueAdmin;

    private User $bookingStaff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
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

        $staffRole = Role::where('name', 'booking_staff')->firstOrFail();
        $this->bookingStaff = User::factory()->create();
        UserRole::create([
            'user_id' => $this->bookingStaff->id,
            'role_id' => $staffRole->id,
            'organization_id' => $this->org->id,
            'venue_id' => $this->venue->id,
        ]);
    }

    public function test_customer_can_upload_manual_transfer_proof_and_admin_verifies_to_confirm_booking(): void
    {
        $holdResp = $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => '2026-11-26T09:00:00+08:00',
            'end_at' => '2026-11-26T10:00:00+08:00',
            'customer_name' => 'Rizky Makassar',
            'customer_phone' => '081234560000',
        ]);

        $holdResp->assertCreated();
        $bookingId = $holdResp->json('data.booking.id');
        $publicToken = $holdResp->json('data.public_token');

        // Upload genuine image transfer proof
        $proofFile = UploadedFile::fake()->image('bukti-mandiri.jpg', 600, 800);

        $uploadResp = $this->postJson('/api/v1/payment-proofs', [
            'public_token' => $publicToken,
            'proof_file' => $proofFile,
            'sender_bank_name' => 'Bank Mandiri',
            'sender_account_holder' => 'RIZKY MAKASSAR',
        ]);

        $uploadResp->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.has_proof', true)
            ->assertJsonPath('data.sender_account_holder', 'RIZKY MAKASSAR');

        $paymentId = $uploadResp->json('data.id');

        // Booking with uploaded proof must NOT be auto-expired while awaiting admin verification
        Booking::whereKey($bookingId)->update(['hold_expires_at' => now()->subMinute()]);
        $expiredCount = app(BookingService::class)->expireOverdueHolds();
        $this->assertSame(0, $expiredCount);
        $this->assertSame('pending_payment', Booking::findOrFail($bookingId)->status);

        // Booking staff (without payment.verify permission) cannot verify payment -> 403
        $this->actingAs($this->bookingStaff, 'sanctum')
            ->postJson("/api/v1/admin/payments/{$paymentId}/verify", [
                'decision' => 'approve',
            ])
            ->assertForbidden();

        // Venue admin / finance downloads proof and approves payment
        $this->actingAs($this->venueAdmin, 'sanctum')
            ->get("/api/v1/admin/payments/{$paymentId}/proof")
            ->assertOk();

        $verifyResp = $this->actingAs($this->venueAdmin, 'sanctum')
            ->postJson("/api/v1/admin/payments/{$paymentId}/verify", [
                'decision' => 'approve',
                'notes' => 'Mutasi Mandiri Rp 250.000 masuk.',
            ]);

        $verifyResp->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.booking.status', 'confirmed');

        $this->assertSame('confirmed', Booking::findOrFail($bookingId)->status);
        $this->assertTrue(
            AuditLog::where('action', 'payment.verified.approved')
                ->where('entity_id', $paymentId)
                ->exists()
        );
    }

    public function test_admin_can_reject_invalid_proof_and_process_refund_for_paid_booking(): void
    {
        $holdResp = $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => '2026-11-26T14:00:00+08:00',
            'end_at' => '2026-11-26T15:00:00+08:00',
            'customer_name' => 'Pelanggan Uji',
            'customer_phone' => '081200009999',
        ]);

        $publicToken = $holdResp->json('data.public_token');
        $paymentId = $holdResp->json('data.booking.payments.0.id');

        // Reject without reason -> 422
        $this->actingAs($this->venueAdmin, 'sanctum')
            ->postJson("/api/v1/admin/payments/{$paymentId}/verify", [
                'decision' => 'reject',
            ])
            ->assertUnprocessable();

        // Reject with reason -> status failed
        $this->actingAs($this->venueAdmin, 'sanctum')
            ->postJson("/api/v1/admin/payments/{$paymentId}/verify", [
                'decision' => 'reject',
                'notes' => 'Nominal pada struk tidak sesuai.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        // Customer re-uploads valid proof and admin approves
        $proofFile = UploadedFile::fake()->image('bukti-revisi.png', 600, 800);
        $this->postJson('/api/v1/payment-proofs', [
            'public_token' => $publicToken,
            'proof_file' => $proofFile,
        ])->assertOk();

        $this->actingAs($this->venueAdmin, 'sanctum')
            ->postJson("/api/v1/admin/payments/{$paymentId}/verify", [
                'decision' => 'approve',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        // Process full refund -> payment status becomes refunded, original payment record preserved
        $refundResp = $this->actingAs($this->venueAdmin, 'sanctum')
            ->postJson('/api/v1/admin/refunds', [
                'payment_id' => $paymentId,
                'amount' => 250000,
                'reason' => 'Hujan lebat / force majeure disetujui admin.',
            ]);

        $refundResp->assertCreated()
            ->assertJsonPath('data.amount', '250000.00');

        $this->assertSame('refunded', Payment::findOrFail($paymentId)->status);
    }

    public function test_malicious_and_polyglot_file_uploads_are_rejected_and_valid_images_are_reencoded(): void
    {
        $holdResp = $this->postJson('/api/v1/booking-holds', [
            'venue_id' => $this->venue->id,
            'court_id' => $this->court1->id,
            'start_at' => '2026-11-26T16:00:00+08:00',
            'end_at' => '2026-11-26T17:00:00+08:00',
            'customer_name' => 'Security Tester',
            'customer_phone' => '081277770000',
        ]);

        $holdResp->assertCreated();
        $publicToken = $holdResp->json('data.public_token');

        // 1. Disguised PHP webshell (.php.jpg) must be rejected
        $phpShell = UploadedFile::fake()->createWithContent(
            'shell.php.jpg',
            "<?php system(\$_GET['cmd']); ?>"
        );
        $this->postJson('/api/v1/payment-proofs', [
            'public_token' => $publicToken,
            'proof_file' => $phpShell,
        ])->assertUnprocessable()->assertJsonValidationErrors(['proof_file']);

        // 2. PDF document must be rejected (strictly raster images allowed)
        $pdfPayload = UploadedFile::fake()->createWithContent(
            'invoice.pdf',
            "%PDF-1.4\n1 0 obj << /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >>"
        );
        $this->postJson('/api/v1/payment-proofs', [
            'public_token' => $publicToken,
            'proof_file' => $pdfPayload,
        ])->assertUnprocessable()->assertJsonValidationErrors(['proof_file']);

        // 3. SVG XSS payload must be rejected
        $svgXss = UploadedFile::fake()->createWithContent(
            'xss.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );
        $this->postJson('/api/v1/payment-proofs', [
            'public_token' => $publicToken,
            'proof_file' => $svgXss,
        ])->assertUnprocessable()->assertJsonValidationErrors(['proof_file']);

        // 4. Genuine JPEG with trailing polyglot PHP code gets sanitized via GD re-encoding
        $cleanJpeg = UploadedFile::fake()->image('struk.php.jpg', 500, 700);
        $polyglotBinary = file_get_contents($cleanJpeg->getRealPath())."\n<?php /* EVIL_POLYGLOT_MARKER */ ?>";
        $polyglotFile = UploadedFile::fake()->createWithContent('struk../shell.php.jpg', $polyglotBinary);

        $uploadResp = $this->postJson('/api/v1/payment-proofs', [
            'public_token' => $publicToken,
            'proof_file' => $polyglotFile,
        ]);

        $uploadResp->assertOk()
            ->assertJsonPath('data.proof_mime_type', 'image/jpeg');

        $payment = Payment::findOrFail($uploadResp->json('data.id'));
        $this->assertStringEndsWith('.jpg', (string) $payment->proof_file_key);
        $this->assertStringEndsWith('.jpg', (string) $payment->proof_original_name);

        $storedBytes = Storage::disk('local')->get($payment->proof_file_key);
        $this->assertNotNull($storedBytes);
        $this->assertStringNotContainsString('EVIL_POLYGLOT_MARKER', $storedBytes);

        // 5. Proof response enforces anti-sniffing and sandbox CSP headers
        $proofResp = $this->actingAs($this->venueAdmin, 'sanctum')
            ->get("/api/v1/admin/payments/{$payment->id}/proof");

        $proofResp->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString("default-src 'none'", (string) $proofResp->headers->get('Content-Security-Policy'));
    }
}
