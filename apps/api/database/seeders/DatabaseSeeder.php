<?php

namespace Database\Seeders;

use App\Models\Court;
use App\Models\OperatingHour;
use App\Models\Organization;
use App\Models\PricingRule;
use App\Models\Promotion;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserVenueAccess;
use App\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $org = Organization::firstOrCreate(
            ['slug' => 'padel-makassar'],
            [
                'name' => 'PADEL MAKASSAR',
                'status' => 'active',
            ]
        );

        $venue = Venue::firstOrCreate(
            ['slug' => 'padel-makassar'],
            [
                'organization_id' => $org->id,
                'name' => 'PADEL MAKASSAR',
                'address' => 'Jl. Letjen Hertasning No.2, RT.001/RW.01, Masale, Kec. Panakkukang, Kota Makassar, Sulawesi Selatan 90222',
                'city' => 'Makassar',
                'timezone' => 'Asia/Makassar',
                'phone' => '082347838252',
                'bank_name' => 'Bank Mandiri',
                'bank_account_number' => '1520037845860',
                'bank_account_holder' => 'NURHASDI NANDAR',
                'description' => 'Venue lapangan padel semi-indoor di kawasan Panakkukang, Kota Makassar.',
                'status' => 'active',
                'slot_interval_minutes' => 60,
                'min_booking_duration_minutes' => 60,
                'max_booking_duration_minutes' => 960,
                'buffer_minutes' => 0,
                'hold_duration_minutes' => 10,
                'cancellation_cutoff_hours' => 24,
                'reschedule_cutoff_hours' => 24,
            ]
        );

        $courts = [
            [
                'code' => '001',
                'name' => 'Lapangan 1',
                'court_type' => 'semi_indoor',
                'surface_type' => 'panoramic_turf',
                'description' => 'Lapangan 1 Semi Indoor — Padel Makassar.',
                'status' => 'active',
            ],
            [
                'code' => '002',
                'name' => 'Lapangan 2',
                'court_type' => 'semi_indoor',
                'surface_type' => 'panoramic_turf',
                'description' => 'Lapangan 2 Semi Indoor — Padel Makassar.',
                'status' => 'active',
            ],
        ];

        foreach ($courts as $courtData) {
            Court::firstOrCreate(
                ['venue_id' => $venue->id, 'code' => $courtData['code']],
                $courtData
            );
        }

        // Jam buka Senin–Minggu 07:00 – 23:00 WITA
        for ($day = 0; $day <= 6; $day++) {
            OperatingHour::firstOrCreate(
                ['venue_id' => $venue->id, 'day_of_week' => $day],
                [
                    'open_time' => '07:00',
                    'close_time' => '23:00',
                    'is_closed' => false,
                ]
            );
        }

        // Tarif reguler pagi-sore (07:00 - 16:00)
        PricingRule::firstOrCreate(
            ['venue_id' => $venue->id, 'name' => 'Regular Hours (07:00 - 16:00)'],
            [
                'court_id' => null,
                'day_of_week' => null,
                'start_time' => '07:00',
                'end_time' => '16:00',
                'price_per_hour' => 250000.00,
                'priority' => 10,
                'is_active' => true,
            ]
        );

        // Tarif peak hour sore-malam (16:00 - 23:00)
        PricingRule::firstOrCreate(
            ['venue_id' => $venue->id, 'name' => 'Peak Hours (16:00 - 23:00)'],
            [
                'court_id' => null,
                'day_of_week' => null,
                'start_time' => '16:00',
                'end_time' => '23:00',
                'price_per_hour' => 350000.00,
                'priority' => 20,
                'is_active' => true,
            ]
        );

        // Tarif khusus Sabtu & Minggu pagi-sore (07:00 - 16:00) -> 300.000/jam
        foreach ([0, 6] as $weekendDay) {
            $dayLabel = $weekendDay === 6 ? 'Sabtu' : 'Minggu';
            PricingRule::firstOrCreate(
                ['venue_id' => $venue->id, 'name' => "Weekend Regular - {$dayLabel} (07:00 - 16:00)"],
                [
                    'court_id' => null,
                    'day_of_week' => $weekendDay,
                    'start_time' => '07:00',
                    'end_time' => '16:00',
                    'price_per_hour' => 300000.00,
                    'priority' => 30,
                    'is_active' => true,
                ]
            );
        }

        Promotion::firstOrCreate(
            ['venue_id' => $venue->id, 'code' => 'OPENING10'],
            [
                'name' => 'Promo Grand Opening Padel Makassar 10%',
                'discount_type' => 'percentage',
                'discount_value' => 10.00,
                'usage_limit' => 100,
                'used_count' => 0,
                'is_active' => true,
            ]
        );

        $superAdminRole = Role::where('name', 'super_admin')->firstOrFail();
        $venueAdminRole = Role::where('name', 'venue_admin')->firstOrFail();

        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@padelmakassar.id'],
            [
                'name' => 'Admin Padel Makassar',
                'phone' => '082347838252',
                'password' => Hash::make('Password123!'),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'is_active' => true,
            ]
        );

        UserRole::firstOrCreate([
            'user_id' => $superAdmin->id,
            'role_id' => $superAdminRole->id,
            'organization_id' => $org->id,
            'venue_id' => $venue->id,
        ]);

        UserVenueAccess::firstOrCreate([
            'user_id' => $superAdmin->id,
            'venue_id' => $venue->id,
            'role_id' => $venueAdminRole->id,
        ]);
    }
}
