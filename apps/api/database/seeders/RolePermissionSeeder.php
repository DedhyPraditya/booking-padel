<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'venue.view' => 'View venue details',
            'venue.manage' => 'Create and update venues',
            'court.view' => 'View courts',
            'court.manage' => 'Create and update courts',
            'schedule.view' => 'View operating hours and schedule blocks',
            'schedule.manage' => 'Manage operating hours and schedule blocks',
            'booking.view' => 'View bookings',
            'booking.create' => 'Create bookings',
            'booking.update' => 'Update booking status or check-in',
            'booking.cancel' => 'Cancel bookings',
            'payment.view' => 'View payments',
            'payment.verify' => 'Verify manual payment proofs',
            'payment.refund' => 'Approve and process refunds',
            'pricing.view' => 'View pricing rules',
            'pricing.manage' => 'Manage pricing rules',
            'customer.view' => 'View customer records',
            'customer.manage' => 'Manage customer records',
            'report.view' => 'View operational and revenue reports',
            'report.export' => 'Export reports',
            'user.manage' => 'Manage staff users',
            'role.manage' => 'Manage roles and permissions',
            'audit.view' => 'View audit logs',
            'settings.manage' => 'Manage system settings',
        ];

        $permissionModels = [];
        foreach ($permissions as $name => $description) {
            $permissionModels[$name] = Permission::firstOrCreate(
                ['name' => $name],
                ['guard_name' => 'api', 'description' => $description]
            );
        }

        $roleMatrix = [
            'super_admin' => [
                'description' => 'Full system administration across all organizations',
                'permissions' => array_keys($permissions),
            ],
            'org_admin' => [
                'description' => 'Manages organization and all its venues',
                'permissions' => [
                    'venue.view', 'venue.manage',
                    'court.view', 'court.manage',
                    'schedule.view', 'schedule.manage',
                    'booking.view', 'booking.create', 'booking.update', 'booking.cancel',
                    'payment.view', 'payment.verify', 'payment.refund',
                    'pricing.view', 'pricing.manage',
                    'customer.view', 'customer.manage',
                    'report.view', 'report.export',
                    'user.manage', 'audit.view', 'settings.manage',
                ],
            ],
            'venue_admin' => [
                'description' => 'Manages assigned venue operations',
                'permissions' => [
                    'venue.view', 'venue.manage',
                    'court.view', 'court.manage',
                    'schedule.view', 'schedule.manage',
                    'booking.view', 'booking.create', 'booking.update', 'booking.cancel',
                    'payment.view', 'payment.verify', 'payment.refund',
                    'pricing.view', 'pricing.manage',
                    'customer.view', 'customer.manage',
                    'report.view', 'audit.view', 'user.manage',
                ],
            ],
            'booking_staff' => [
                'description' => 'Creates and manages bookings for assigned venue(s)',
                'permissions' => [
                    'venue.view', 'court.view', 'schedule.view',
                    'booking.view', 'booking.create', 'booking.update', 'booking.cancel',
                    'customer.view', 'customer.manage',
                ],
            ],
            'finance_staff' => [
                'description' => 'Verifies payments and handles refund workflow',
                'permissions' => [
                    'venue.view', 'booking.view',
                    'payment.view', 'payment.verify', 'payment.refund',
                    'report.view', 'report.export',
                ],
            ],
            'operations_staff' => [
                'description' => 'Daily schedule and customer check-in',
                'permissions' => [
                    'venue.view', 'court.view', 'schedule.view',
                    'booking.view', 'booking.update',
                ],
            ],
            'customer' => [
                'description' => 'Customer booking and profile access',
                'permissions' => [],
            ],
        ];

        foreach ($roleMatrix as $roleName => $config) {
            $role = Role::firstOrCreate(
                ['name' => $roleName],
                ['guard_name' => 'api', 'description' => $config['description']]
            );

            $permIds = collect($config['permissions'])
                ->map(fn (string $p) => $permissionModels[$p]->id)
                ->all();

            $role->permissions()->sync($permIds);
        }
    }
}
