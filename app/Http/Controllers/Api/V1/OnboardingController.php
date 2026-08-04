<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class OnboardingController extends Controller
{
    use ApiResponse;

    /**
     * Handle tenant onboarding and admin creation.
     */
    public function onboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'company_slug' => 'nullable|string|max:255|unique:tenants,slug',
            'domain' => 'nullable|string|max:255|unique:tenants,domain',
            'plan_id' => 'nullable|exists:subscription_plans,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:50',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        return DB::transaction(function () use ($validated) {
            // 1. Create Tenant
            $slug = $validated['company_slug'] ?? Str::slug($validated['company_name']);
            $tenant = Tenant::create([
                'name' => $validated['company_name'],
                'slug' => $slug,
                'domain' => $validated['domain'] ?? null,
                'status' => 'active',
            ]);

            // Set context for current transaction
            TenantContext::setTenant($tenant);
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

            // 2. Assign Subscription Plan
            $plan = isset($validated['plan_id'])
                ? SubscriptionPlan::find($validated['plan_id'])
                : SubscriptionPlan::where('slug', 'starter')->first() ?? SubscriptionPlan::first();

            if ($plan) {
                Subscription::create([
                    'tenant_id' => $tenant->id,
                    'subscription_plan_id' => $plan->id,
                    'status' => 'trialing',
                    'trial_ends_at' => now()->addDays(14),
                ]);
            }

            // 3. Create Tenant Admin User
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'status' => 'active',
            ]);

            // 4. Ensure Admin role exists for tenant and assign it
            $adminRole = Role::firstOrCreate([
                'name' => 'Admin',
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ]);

            $user->assignRole($adminRole);

            // 5. Audit Log
            AuditLogger::log(
                action: 'tenant_onboarded',
                auditable: $tenant,
                newValues: ['name' => $tenant->name, 'slug' => $tenant->slug],
                tenantId: $tenant->id,
                userId: $user->id
            );

            // 6. Generate Sanctum token
            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->successResponse([
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                ],
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status,
                ],
            ], 'Tenant onboarding completed successfully.', 201);
        });
    }
}
