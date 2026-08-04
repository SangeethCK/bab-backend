<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_data_is_strictly_isolated(): void
    {
        // Create Tenant A
        $tenantA = Tenant::create(['name' => 'Company A', 'slug' => 'company-a', 'status' => 'active']);
        $userA = User::create([
            'tenant_id' => $tenantA->id,
            'name' => 'User A',
            'email' => 'usera@companya.com',
            'password' => bcrypt('password'),
        ]);

        // Create Tenant B
        $tenantB = Tenant::create(['name' => 'Company B', 'slug' => 'company-b', 'status' => 'active']);
        $userB = User::create([
            'tenant_id' => $tenantB->id,
            'name' => 'User B',
            'email' => 'userb@companyb.com',
            'password' => bcrypt('password'),
        ]);

        // Create Audit Logs for both tenants
        AuditLog::create(['tenant_id' => $tenantA->id, 'action' => 'action_a', 'user_id' => $userA->id]);
        AuditLog::create(['tenant_id' => $tenantB->id, 'action' => 'action_b', 'user_id' => $userB->id]);

        // Authenticate as User A and set Tenant A context
        TenantContext::setTenant($tenantA);

        $response = $this->actingAs($userA, 'sanctum')
            ->withHeader('X-Tenant-ID', $tenantA->id)
            ->getJson('/api/v1/audit-logs');

        $response->assertStatus(200);
        $response->assertJsonFragment(['action' => 'action_a']);
        $response->assertJsonMissing(['action' => 'action_b']);
    }

    public function test_user_cannot_access_another_tenants_data(): void
    {
        $tenantA = Tenant::create(['name' => 'Company A', 'slug' => 'company-a', 'status' => 'active']);
        $userA = User::create([
            'tenant_id' => $tenantA->id,
            'name' => 'User A',
            'email' => 'admina@companya.com',
            'password' => bcrypt('password'),
        ]);

        $tenantB = Tenant::create(['name' => 'Company B', 'slug' => 'company-b', 'status' => 'active']);

        // User A trying to claim Tenant B header
        $response = $this->actingAs($userA, 'sanctum')
            ->withHeader('X-Tenant-Slug', 'company-b')
            ->getJson('/api/v1/tenant');

        $response->assertStatus(200);
        // Should return Tenant A's information because auth user's tenant_id takes precedence
        $response->assertJsonPath('data.tenant.id', $tenantA->id);
    }
}
