<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Chair;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChairManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected User $userA;
    protected Tenant $tenantB;
    protected User $userB;
    protected Employee $employeeA;
    protected Service $serviceA;

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

        TenantContext::setTenant($this->tenantA);

        $this->employeeA = Employee::create([
            'tenant_id' => $this->tenantA->id,
            'first_name' => 'Dwight',
            'last_name' => 'Barber',
            'email' => 'dwight@tenanta.com',
            'phone' => '+15550001111',
            'designation' => 'Master Barber',
            'work_start_time' => '08:00:00',
            'work_end_time' => '20:00:00',
            'max_concurrent_bookings' => 2,
            'status' => 'active',
        ]);

        $this->serviceA = Service::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Executive Cut',
            'duration_minutes' => 30,
            'price' => 50.00,
            'is_active' => true,
        ]);

        $this->employeeA->services()->sync([$this->serviceA->id]);
    }

    public function test_chair_creation_auto_generates_chair_number(): void
    {
        $response = $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/chairs', [
                'name' => 'Station 1',
                'sort_order' => 1,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.chair_number', 'CHAIR-01');
        $response->assertJsonPath('data.name', 'Station 1');
        $response->assertJsonPath('data.status', 'available');

        $this->assertDatabaseHas('chairs', [
            'tenant_id' => $this->tenantA->id,
            'name' => 'Station 1',
            'chair_number' => 'CHAIR-01',
        ]);
    }

    public function test_chair_update_and_delete(): void
    {
        $chair = Chair::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'VIP Station',
            'chair_number' => 'CHAIR-VIP',
            'status' => 'available',
        ]);

        $updateResponse = $this->actingAs($this->userA, 'sanctum')
            ->putJson("/api/v1/chairs/{$chair->id}", [
                'name' => 'Updated VIP Station',
                'status' => 'maintenance',
            ]);

        $updateResponse->assertStatus(200);
        $updateResponse->assertJsonPath('data.name', 'Updated VIP Station');
        $updateResponse->assertJsonPath('data.status', 'maintenance');

        $deleteResponse = $this->actingAs($this->userA, 'sanctum')
            ->deleteJson("/api/v1/chairs/{$chair->id}");

        $deleteResponse->assertStatus(200);
        $this->assertSoftDeleted('chairs', ['id' => $chair->id]);
    }

    public function test_multi_tenancy_isolation_for_chairs(): void
    {
        $chairA = Chair::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Tenant A Chair',
            'chair_number' => 'A-01',
        ]);

        TenantContext::setTenant($this->tenantB);
        $chairB = Chair::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Tenant B Chair',
            'chair_number' => 'B-01',
        ]);

        // User A should only see Tenant A's chairs
        $responseA = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/chairs');

        $responseA->assertStatus(200);
        $responseA->assertJsonFragment(['name' => 'Tenant A Chair']);
        $responseA->assertJsonMissing(['name' => 'Tenant B Chair']);
    }

    public function test_assign_employee_to_chair(): void
    {
        $chair = Chair::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Chair 1',
            'chair_number' => 'CHAIR-01',
            'status' => 'available',
        ]);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->postJson("/api/v1/chairs/{$chair->id}/assign-employee", [
                'employee_id' => $this->employeeA->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.employee_id', $this->employeeA->id);
        $this->assertEquals($this->employeeA->id, $chair->fresh()->employee_id);
    }

    public function test_pos_chair_visual_grid_dashboard_returns_live_status(): void
    {
        $chair = Chair::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Master Station 1',
            'chair_number' => 'CHAIR-01',
            'employee_id' => $this->employeeA->id,
            'status' => 'available',
            'sort_order' => 1,
        ]);

        $customer = Customer::create([
            'tenant_id' => $this->tenantA->id,
            'customer_code' => 'CUST-0001',
            'name' => 'Robert Johnson',
            'mobile' => '+15559998888',
        ]);

        // Create an active in_progress booking on this chair
        Booking::create([
            'tenant_id' => $this->tenantA->id,
            'booking_code' => 'BKG-0001',
            'customer_id' => $customer->id,
            'employee_id' => $this->employeeA->id,
            'chair_id' => $chair->id,
            'service_id' => $this->serviceA->id,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addMinutes(20),
            'status' => 'in_progress',
            'total_price' => 50.00,
        ]);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/chairs/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('data.summary.total_chairs', 1);
        $response->assertJsonPath('data.summary.occupied', 1);
        $response->assertJsonPath('data.chairs.0.status', 'occupied');
        $response->assertJsonPath('data.chairs.0.status_color', '#EF4444');
        $response->assertJsonPath('data.chairs.0.border_color', '#DC2626');
        $response->assertJsonPath('data.chairs.0.chair_number', 'CHAIR-01');
        $response->assertJsonPath('data.chairs.0.active_booking.customer.name', 'Robert Johnson');
        $response->assertJsonPath('data.chairs.0.active_booking.service.name', 'Executive Cut');
    }

    public function test_create_booking_with_chair_id(): void
    {
        $chair = Chair::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Chair 2',
            'chair_number' => 'CHAIR-02',
            'employee_id' => $this->employeeA->id,
            'status' => 'available',
        ]);

        $customer = Customer::create([
            'tenant_id' => $this->tenantA->id,
            'customer_code' => 'CUST-0002',
            'name' => 'Jim Halpert',
            'mobile' => '+15557776666',
        ]);

        $bookingDate = Carbon::today()->addDay()->format('Y-m-d');
        $startTime = "{$bookingDate} 10:00:00";

        $response = $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'customer_id' => $customer->id,
                'employee_id' => $this->employeeA->id,
                'chair_id' => $chair->id,
                'service_id' => $this->serviceA->id,
                'start_time' => $startTime,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.chair_id', $chair->id);
        $this->assertDatabaseHas('bookings', [
            'tenant_id' => $this->tenantA->id,
            'chair_id' => $chair->id,
            'employee_id' => $this->employeeA->id,
        ]);
    }
}
