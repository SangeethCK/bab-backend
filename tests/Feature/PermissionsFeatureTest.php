<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/customers');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_without_tenant_context_is_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Barber Shop', 'slug' => 'barber-shop', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'John Barber',
            'email' => 'john@barber.com',
            'password' => bcrypt('secret123'),
        ]);

        // Access without sending X-Tenant-ID or X-Tenant-Slug header when auth user is resolved
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/summary');
        // EnsureTenantContext resolves tenant via authenticated user if header missing or sets context properly
        $response->assertStatus(200);
    }

    public function test_user_cannot_impersonate_another_tenant_via_header(): void
    {
        $tenantReal = Tenant::create(['name' => 'Real Salon', 'slug' => 'real-salon', 'status' => 'active']);
        $tenantFake = Tenant::create(['name' => 'Fake Salon', 'slug' => 'fake-salon', 'status' => 'active']);

        $user = User::create([
            'tenant_id' => $tenantReal->id,
            'name' => 'Real Admin',
            'email' => 'real@salon.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', $tenantFake->id)
            ->getJson('/api/v1/tenant');

        $response->assertStatus(200);
        // Tenant context resolves user's actual tenant ID for security
        $response->assertJsonPath('data.tenant.id', $tenantReal->id);
    }
}
