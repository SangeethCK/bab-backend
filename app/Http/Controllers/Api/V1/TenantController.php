<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TenantContext;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    use ApiResponse;

    /**
     * Display current tenant details.
     */
    public function show(Request $request): JsonResponse
    {
        $tenant = TenantContext::getTenant() ?? $request->user()?->tenant;

        if (!$tenant) {
            return $this->errorResponse('Tenant context not found.', 404);
        }

        $tenant->load('subscription.plan');

        return $this->successResponse([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'status' => $tenant->status,
                'settings' => $tenant->settings,
                'subscription' => $tenant->subscription ? [
                    'status' => $tenant->subscription->status,
                    'trial_ends_at' => $tenant->subscription->trial_ends_at,
                    'ends_at' => $tenant->subscription->ends_at,
                    'plan' => $tenant->subscription->plan ? [
                        'name' => $tenant->subscription->plan->name,
                        'price_monthly' => $tenant->subscription->plan->price_monthly,
                        'max_users' => $tenant->subscription->plan->max_users,
                        'features' => $tenant->subscription->plan->features,
                    ] : null,
                ] : null,
            ],
        ], 'Tenant details retrieved successfully.');
    }
}
