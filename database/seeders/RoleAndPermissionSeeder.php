<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Standard permissions list
        $permissions = [
            'manage_tenant_settings',
            'manage_users',
            'view_reports',
            'manage_bookings',
            'view_bookings',
            'manage_customers',
            'view_customers',
            'manage_services',
            'manage_invoices',
            'view_invoices',
            'manage_expenses',
            'view_audit_logs',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }
    }
}
