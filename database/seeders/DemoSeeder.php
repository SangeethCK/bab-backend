<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Primary Tenant & Secondary Tenant
        $tenant1 = Tenant::firstOrCreate(
            ['slug' => 'barber-alpha'],
            [
                'name' => 'Barber Salon Alpha',
                'status' => 'active',
            ]
        );

        $tenant2 = Tenant::firstOrCreate(
            ['slug' => 'tenant_downtown'],
            [
                'name' => 'Downtown Barber Lounge',
                'status' => 'active',
            ]
        );

        TenantContext::setTenant($tenant1);

        // 2. Create User Accounts (Admin, Staff, Customer)
        $admin = User::firstOrCreate(
            ['email' => 'admin@barber.com'],
            [
                'tenant_id' => $tenant1->id,
                'name' => 'Barber Salon Admin',
                'password' => Hash::make('password123'),
                'status' => 'active',
            ]
        );

        $staff = User::firstOrCreate(
            ['email' => 'staff@barber.com'],
            [
                'tenant_id' => $tenant1->id,
                'name' => 'Barber Staff Member',
                'password' => Hash::make('password123'),
                'status' => 'active',
            ]
        );

        $customerUser = User::firstOrCreate(
            ['email' => 'customer@barber.com'],
            [
                'tenant_id' => $tenant1->id,
                'name' => 'Valued Salon Customer',
                'password' => Hash::make('password123'),
                'status' => 'active',
            ]
        );

        // Assign Roles if spatie/laravel-permission is present
        if (class_exists(\Spatie\Permission\Models\Role::class)) {
            if (function_exists('setPermissionsTeamId')) {
                setPermissionsTeamId($tenant1->id);
            }
            $admin->assignRole('admin');
            $staff->assignRole('staff');
            $customerUser->assignRole('customer');
        }

        // 3. Create Services
        $haircut = Service::firstOrCreate(
            ['name' => 'Signature Haircut', 'tenant_id' => $tenant1->id],
            ['duration_minutes' => 30, 'price' => 35.00]
        );

        $beard = Service::firstOrCreate(
            ['name' => 'Beard Trim & Styling', 'tenant_id' => $tenant1->id],
            ['duration_minutes' => 20, 'price' => 20.00]
        );

        $facial = Service::firstOrCreate(
            ['name' => 'Executive Facial Therapy', 'tenant_id' => $tenant1->id],
            ['duration_minutes' => 45, 'price' => 50.00]
        );

        $combo = Service::firstOrCreate(
            ['name' => 'Premium Fade & Beard Combo', 'tenant_id' => $tenant1->id],
            ['duration_minutes' => 50, 'price' => 55.00]
        );

        // 4. Create Employees with Work Shifts and Concurrent Capacity
        $sam = Employee::firstOrCreate(
            ['email' => 'sam@barber.com', 'tenant_id' => $tenant1->id],
            [
                'user_id' => $staff->id,
                'first_name' => 'Sam',
                'last_name' => 'Stylist',
                'phone' => '+15559876543',
                'designation' => 'Senior Hair Stylist',
                'work_start_time' => '08:00:00',
                'work_end_time' => '21:00:00',
                'max_concurrent_bookings' => 1,
                'status' => 'active',
            ]
        );

        $dwight = Employee::firstOrCreate(
            ['email' => 'dwight@barber.com', 'tenant_id' => $tenant1->id],
            [
                'first_name' => 'Dwight',
                'last_name' => 'Barber',
                'phone' => '+15559876544',
                'designation' => 'Master Barber',
                'work_start_time' => '08:00:00',
                'work_end_time' => '21:00:00',
                'max_concurrent_bookings' => 2, // Multi-chair capacity
                'status' => 'active',
            ]
        );

        $alex = Employee::firstOrCreate(
            ['email' => 'alex@barber.com', 'tenant_id' => $tenant1->id],
            [
                'first_name' => 'Alex',
                'last_name' => 'Groomer',
                'phone' => '+15559876545',
                'designation' => 'Beard Specialist',
                'work_start_time' => '09:00:00',
                'work_end_time' => '18:00:00',
                'max_concurrent_bookings' => 1,
                'status' => 'active',
            ]
        );

        $serviceIds = [$haircut->id, $beard->id, $facial->id, $combo->id];
        $sam->services()->syncWithoutDetaching($serviceIds);
        $dwight->services()->syncWithoutDetaching($serviceIds);
        $alex->services()->syncWithoutDetaching([$haircut->id, $beard->id]);

        // 5. Create Customers
        $cust1 = Customer::firstOrCreate(
            ['mobile' => '9876543210', 'tenant_id' => $tenant1->id],
            [
                'customer_code' => 'CUST-0001',
                'name' => 'Robert Johnson',
                'email' => 'robert@example.com',
                'notes' => 'VIP Client - Preference: Skin Fade',
            ]
        );

        $cust2 = Customer::firstOrCreate(
            ['mobile' => '9876543211', 'tenant_id' => $tenant1->id],
            [
                'customer_code' => 'CUST-0002',
                'name' => 'Michael Scott',
                'email' => 'michael@example.com',
                'notes' => 'Prefers Executive Facial Therapy',
            ]
        );

        $cust3 = Customer::firstOrCreate(
            ['mobile' => '9876543212', 'tenant_id' => $tenant1->id],
            [
                'customer_code' => 'CUST-0003',
                'name' => 'Jim Halpert',
                'email' => 'jim@example.com',
                'notes' => 'Regular Beard Trim client',
            ]
        );

        // 6. Create Bookings (Active, Scheduled, Completed)
        
        // Active "in_progress" booking for live service timer & busy status testing
        $activeBooking = Booking::firstOrCreate(
            ['booking_code' => 'BK-0001', 'tenant_id' => $tenant1->id],
            [
                'customer_id' => $cust1->id,
                'employee_id' => $sam->id,
                'service_id' => $haircut->id,
                'start_time' => now()->subMinutes(15),
                'end_time' => now()->addMinutes(15),
                'status' => 'in_progress',
                'total_price' => 35.00,
                'notes' => 'Active haircut in progress (15m remaining)',
            ]
        );

        // Scheduled booking for today
        $scheduledBooking = Booking::firstOrCreate(
            ['booking_code' => 'BK-0002', 'tenant_id' => $tenant1->id],
            [
                'customer_id' => $cust2->id,
                'employee_id' => $dwight->id,
                'service_id' => $combo->id,
                'start_time' => now()->addHours(1),
                'end_time' => now()->addHours(1)->addMinutes(50),
                'status' => 'scheduled',
                'total_price' => 55.00,
                'notes' => 'Scheduled appointment for today',
            ]
        );

        // Checked in customer in lobby
        $checkedInBooking = Booking::firstOrCreate(
            ['booking_code' => 'BK-0003', 'tenant_id' => $tenant1->id],
            [
                'customer_id' => $cust3->id,
                'employee_id' => $alex->id,
                'service_id' => $beard->id,
                'start_time' => now()->addMinutes(30),
                'end_time' => now()->addMinutes(50),
                'status' => 'checked_in',
                'total_price' => 20.00,
                'notes' => 'Customer checked in lobby',
            ]
        );

        // Completed booking with paid invoice
        $completedBooking = Booking::firstOrCreate(
            ['booking_code' => 'BK-0004', 'tenant_id' => $tenant1->id],
            [
                'customer_id' => $cust1->id,
                'employee_id' => $dwight->id,
                'service_id' => $facial->id,
                'start_time' => now()->subDays(1)->setHour(11)->setMinute(0),
                'end_time' => now()->subDays(1)->setHour(11)->setMinute(45),
                'status' => 'completed',
                'total_price' => 50.00,
                'notes' => 'Completed service from yesterday',
            ]
        );

        // 7. Create Invoices & Payments
        $inv1 = Invoice::firstOrCreate(
            ['invoice_number' => 'INV-00001', 'tenant_id' => $tenant1->id],
            [
                'customer_id' => $cust1->id,
                'booking_id' => $activeBooking->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
                'status' => 'unpaid',
                'subtotal' => 35.00,
                'tax_amount' => 0.00,
                'discount_amount' => 0.00,
                'total_amount' => 35.00,
                'paid_amount' => 0.00,
                'due_amount' => 35.00,
            ]
        );

        $inv2 = Invoice::firstOrCreate(
            ['invoice_number' => 'INV-00002', 'tenant_id' => $tenant1->id],
            [
                'customer_id' => $cust1->id,
                'booking_id' => $completedBooking->id,
                'issue_date' => now()->subDays(1)->toDateString(),
                'due_date' => now()->subDays(1)->toDateString(),
                'status' => 'paid',
                'subtotal' => 50.00,
                'tax_amount' => 0.00,
                'discount_amount' => 0.00,
                'total_amount' => 50.00,
                'paid_amount' => 50.00,
                'due_amount' => 0.00,
            ]
        );

        Payment::firstOrCreate(
            ['payment_number' => 'PAY-00001', 'tenant_id' => $tenant1->id],
            [
                'invoice_id' => $inv2->id,
                'customer_id' => $cust1->id,
                'payment_method' => 'card',
                'amount' => 50.00,
                'payment_date' => now()->subDays(1),
            ]
        );
    }
}
