<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationFeatureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private User $userA;
    private Tenant $tenantB;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create(['name' => 'Barber Salon Alpha', 'slug' => 'alpha', 'status' => 'active']);
        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Manager Alpha',
            'email' => 'alpha@barber.com',
            'password' => bcrypt('password123'),
        ]);

        $this->tenantB = Tenant::create(['name' => 'Barber Salon Beta', 'slug' => 'beta', 'status' => 'active']);
        $this->userB = User::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Manager Beta',
            'email' => 'beta@barber.com',
            'password' => bcrypt('password123'),
        ]);
    }

    public function test_tenant_cannot_view_or_modify_other_tenants_customers(): void
    {
        // Customer for Tenant B
        TenantContext::setTenant($this->tenantB);
        $customerB = Customer::create([
            'tenant_id' => $this->tenantB->id,
            'customer_code' => 'CUST-B001',
            'name' => 'John Doe',
            'mobile' => '+1234567890',
            'email' => 'john@beta.com',
        ]);

        // Authenticate as Tenant A User
        TenantContext::setTenant($this->tenantA);

        // Try getting Tenant B customer
        $response = $this->actingAs($this->userA, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenantA->id)
            ->getJson("/api/v1/customers/{$customerB->id}");

        $response->assertStatus(404);

        // Try putting/updating Tenant B customer
        $updateResponse = $this->actingAs($this->userA, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenantA->id)
            ->putJson("/api/v1/customers/{$customerB->id}", [
                'name' => 'Hacked Name'
            ]);

        $updateResponse->assertStatus(404);
    }

    public function test_tenant_cannot_view_other_tenants_bookings_or_invoices(): void
    {
        TenantContext::setTenant($this->tenantB);

        $customerB = Customer::create([
            'tenant_id' => $this->tenantB->id,
            'customer_code' => 'CUST-B002',
            'name' => 'Jane Smith',
            'mobile' => '+1987654321',
        ]);
        $employeeB = Employee::create([
            'tenant_id' => $this->tenantB->id,
            'first_name' => 'Staff',
            'last_name' => 'Beta',
            'email' => 'staff@beta.com',
            'phone' => '123456789',
        ]);
        $serviceB = Service::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'price' => 25.00,
        ]);

        $bookingB = Booking::create([
            'tenant_id' => $this->tenantB->id,
            'booking_code' => 'BK-B1',
            'customer_id' => $customerB->id,
            'employee_id' => $employeeB->id,
            'service_id' => $serviceB->id,
            'start_time' => now(),
            'end_time' => now()->addMinutes(30),
            'status' => 'scheduled',
            'total_price' => 25.00,
        ]);

        $invoiceB = Invoice::create([
            'tenant_id' => $this->tenantB->id,
            'invoice_number' => 'INV-B1',
            'customer_id' => $customerB->id,
            'booking_id' => $bookingB->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'unpaid',
            'subtotal' => 25.00,
            'tax_amount' => 0.00,
            'discount_amount' => 0.00,
            'total_amount' => 25.00,
            'paid_amount' => 0.00,
            'due_amount' => 25.00,
        ]);

        // Tenant A authentication
        TenantContext::setTenant($this->tenantA);

        $bookingResponse = $this->actingAs($this->userA, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenantA->id)
            ->getJson("/api/v1/bookings/{$bookingB->id}");

        $bookingResponse->assertStatus(404);

        $invoiceResponse = $this->actingAs($this->userA, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenantA->id)
            ->getJson("/api/v1/invoices/{$invoiceB->id}");

        $invoiceResponse->assertStatus(404);
    }
}
