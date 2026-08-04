<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected User $userA;
    protected Tenant $tenantB;
    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Admin A',
            'email' => 'admin@tenanta.com',
            'password' => bcrypt('password'),
        ]);

        $this->tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
        $this->userB = User::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Admin B',
            'email' => 'admin@tenantb.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_customer_creation_auto_generates_customer_code(): void
    {
        $response = $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/customers', [
                'name' => 'Robert Johnson',
                'mobile' => '9876543210',
                'email' => 'robert@example.com',
                'notes' => 'VIP Regular Customer',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.customer_code', 'CUST-0001');
        $this->assertDatabaseHas('customers', [
            'tenant_id' => $this->tenantA->id,
            'name' => 'Robert Johnson',
            'customer_code' => 'CUST-0001',
        ]);
    }

    public function test_customer_search_by_mobile_name_and_code(): void
    {
        TenantContext::setTenant($this->tenantA);

        Customer::create([
            'tenant_id' => $this->tenantA->id,
            'customer_code' => 'CUST-0001',
            'name' => 'Alice Smith',
            'mobile' => '555111222',
        ]);

        Customer::create([
            'tenant_id' => $this->tenantA->id,
            'customer_code' => 'CUST-0002',
            'name' => 'Bob Davis',
            'mobile' => '555999888',
        ]);

        // Search by mobile
        $resMobile = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/customers?search=555111');
        $resMobile->assertStatus(200);
        $resMobile->assertJsonCount(1, 'data.data');
        $resMobile->assertJsonPath('data.data.0.name', 'Alice Smith');

        // Search by customer code
        $resCode = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/customers?search=CUST-0002');
        $resCode->assertStatus(200);
        $resCode->assertJsonCount(1, 'data.data');
        $resCode->assertJsonPath('data.data.0.name', 'Bob Davis');
    }

    public function test_customer_isolation_between_tenants(): void
    {
        TenantContext::setTenant($this->tenantB);
        $custB = Customer::create([
            'tenant_id' => $this->tenantB->id,
            'customer_code' => 'CUST-0001',
            'name' => 'Tenant B Secret Customer',
            'mobile' => '999000111',
        ]);

        // User A attempts to view Customer of Tenant B
        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/v1/customers/{$custB->id}");

        $response->assertStatus(404);
    }

    public function test_customer_history_endpoint(): void
    {
        TenantContext::setTenant($this->tenantA);
        $customer = Customer::create([
            'tenant_id' => $this->tenantA->id,
            'customer_code' => 'CUST-0005',
            'name' => 'Charlie Brown',
            'mobile' => '1234567890',
        ]);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/v1/customers/{$customer->id}/history");

        $response->assertStatus(200);
        $response->assertJsonPath('data.customer.id', $customer->id);
        $response->assertJsonStructure(['data' => ['customer', 'stats', 'recent_activity']]);
    }
}
