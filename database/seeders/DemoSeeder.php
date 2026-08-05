<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
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
        // 1. Create Demo Tenant
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'barber-alpha'],
            [
                'name' => 'Barber Salon Alpha',
                'status' => 'active',
            ]
        );

        TenantContext::setTenant($tenant);

        // 2. Create Demo Admin User
        $user = User::firstOrCreate(
            ['email' => 'admin@barber.com'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Barber Admin',
                'password' => Hash::make('password123'),
                'status' => 'active',
            ]
        );

        // 3. Create Services
        $haircut = Service::firstOrCreate(
            ['name' => 'Signature Haircut', 'tenant_id' => $tenant->id],
            ['duration_minutes' => 30, 'price' => 35.00]
        );

        $beard = Service::firstOrCreate(
            ['name' => 'Beard Trim & Styling', 'tenant_id' => $tenant->id],
            ['duration_minutes' => 20, 'price' => 20.00]
        );

        $facial = Service::firstOrCreate(
            ['name' => 'Executive Facial Therapy', 'tenant_id' => $tenant->id],
            ['duration_minutes' => 45, 'price' => 50.00]
        );

        // 4. Create Employee
        $employee = Employee::firstOrCreate(
            ['email' => 'sam@barber.com', 'tenant_id' => $tenant->id],
            [
                'first_name' => 'Sam',
                'last_name' => 'Stylist',
                'phone' => '+15559876543',
                'designation' => 'Senior Hair Stylist',
                'status' => 'active',
            ]
        );
        $employee->services()->syncWithoutDetaching([$haircut->id, $beard->id, $facial->id]);

        // 5. Create Customer
        $customer = Customer::firstOrCreate(
            ['mobile' => '9876543210', 'tenant_id' => $tenant->id],
            [
                'customer_code' => 'CUST-0001',
                'name' => 'Robert Johnson',
                'email' => 'robert@example.com',
                'notes' => 'VIP Client - Likes sharp fade',
            ]
        );

        // 6. Create Booking
        $booking = Booking::firstOrCreate(
            ['booking_code' => 'BK-0001', 'tenant_id' => $tenant->id],
            [
                'customer_id' => $customer->id,
                'employee_id' => $employee->id,
                'service_id' => $haircut->id,
                'start_time' => now()->setHour(14)->setMinute(0),
                'end_time' => now()->setHour(14)->setMinute(30),
                'status' => 'scheduled',
                'total_price' => 35.00,
                'notes' => 'First time client appointment',
            ]
        );

        // 7. Create Invoice
        Invoice::firstOrCreate(
            ['invoice_number' => 'INV-00001', 'tenant_id' => $tenant->id],
            [
                'customer_id' => $customer->id,
                'booking_id' => $booking->id,
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
    }
}
