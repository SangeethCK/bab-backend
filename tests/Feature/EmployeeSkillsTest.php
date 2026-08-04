<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeSkillsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Service $service1;
    protected Service $service2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Barber Club', 'slug' => 'barber-club']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@barberclub.com',
            'password' => bcrypt('password'),
        ]);

        TenantContext::setTenant($this->tenant);

        $this->service1 = Service::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Beard Trim',
            'duration_minutes' => 20,
            'price' => 25.00,
        ]);

        $this->service2 = Service::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hair Coloring',
            'duration_minutes' => 60,
            'price' => 80.00,
        ]);
    }

    public function test_create_employee_with_attached_services(): void
    {
        $payload = [
            'first_name' => 'David',
            'last_name' => 'Miller',
            'phone' => '1112223333',
            'designation' => 'Master Stylist',
            'services' => [
                [
                    'service_id' => $this->service1->id,
                    'custom_duration_minutes' => 15,
                    'custom_price' => 30.00,
                ],
                [
                    'service_id' => $this->service2->id,
                ],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/employees', $payload);

        $response->assertStatus(201);
        $response->assertJsonCount(2, 'data.services');
        $this->assertDatabaseHas('employee_service', [
            'service_id' => $this->service1->id,
            'custom_duration_minutes' => 15,
        ]);
    }

    public function test_sync_employee_service_skills(): void
    {
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Sarah',
            'phone' => '4445556666',
        ]);

        $syncRes = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/employees/{$employee->id}/skills", [
                'services' => [
                    ['service_id' => $this->service2->id],
                ],
            ]);

        $syncRes->assertStatus(200);
        $syncRes->assertJsonCount(1, 'data.services');
        $syncRes->assertJsonPath('data.services.0.id', $this->service2->id);
    }
}
