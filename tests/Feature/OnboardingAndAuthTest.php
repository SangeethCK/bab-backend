<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingAndAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SubscriptionPlan::create([
            'name' => 'Starter',
            'slug' => 'starter',
            'price_monthly' => 29,
            'max_users' => 5,
        ]);
    }

    public function test_tenant_onboarding_creates_company_admin_and_subscription(): void
    {
        $payload = [
            'company_name' => 'Acme Salon',
            'company_slug' => 'acme-salon',
            'name' => 'Jane Admin',
            'email' => 'jane@acmesalon.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ];

        $response = $this->postJson('/api/v1/onboard', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'token',
                'user' => ['id', 'name', 'email', 'roles'],
                'tenant' => ['id', 'name', 'slug', 'status'],
            ],
        ]);

        $this->assertDatabaseHas('tenants', ['slug' => 'acme-salon']);
        $this->assertDatabaseHas('users', ['email' => 'jane@acmesalon.com']);
        $this->assertDatabaseHas('subscriptions', ['status' => 'trialing']);
    }

    public function test_user_can_login_and_retrieve_profile(): void
    {
        $tenant = Tenant::create(['name' => 'Barber Hub', 'slug' => 'barber-hub']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'John Barber',
            'email' => 'john@barberhub.com',
            'password' => bcrypt('Password123!'),
            'status' => 'active',
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'john@barberhub.com',
            'password' => 'Password123!',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('data.token');

        $meResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me');

        $meResponse->assertStatus(200);
        $meResponse->assertJsonPath('data.user.email', 'john@barberhub.com');
        $meResponse->assertJsonPath('data.tenant.slug', 'barber-hub');
    }

    public function test_user_logout_revokes_token(): void
    {
        $tenant = Tenant::create(['name' => 'Glow Spa', 'slug' => 'glow-spa']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Alice Manager',
            'email' => 'alice@glowspa.com',
            'password' => bcrypt('Password123!'),
            'status' => 'active',
        ]);

        $token = $user->createToken('test_token')->plainTextToken;

        $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200);

        // Reset auth guards to verify token invalidation
        auth()->forgetGuards();

        $meResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me');

        $meResponse->assertStatus(401);
    }
}
