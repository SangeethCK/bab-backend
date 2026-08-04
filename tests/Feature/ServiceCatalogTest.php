<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Salon Glow', 'slug' => 'salon-glow']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Manager User',
            'email' => 'manager@salonglow.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_service_catalog_crud(): void
    {
        // 1. Create Service
        $createRes = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/services', [
                'name' => 'Haircut & Styling',
                'description' => 'Premium haircut with shampoo',
                'duration_minutes' => 45,
                'price' => 50.00,
                'tax_percentage' => 5.00,
            ]);

        $createRes->assertStatus(201);
        $serviceId = $createRes->json('data.id');

        // 2. List Services
        $listRes = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/services');
        $listRes->assertStatus(200);
        $listRes->assertJsonCount(1, 'data.data');

        // 3. Update Service
        $updateRes = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/services/{$serviceId}", [
                'price' => 60.00,
            ]);
        $updateRes->assertStatus(200);
        $updateRes->assertJsonPath('data.price', '60.00');

        // 4. Soft Delete Service
        $deleteRes = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/services/{$serviceId}");
        $deleteRes->assertStatus(200);

        $this->assertSoftDeleted('services', ['id' => $serviceId]);
    }
}
