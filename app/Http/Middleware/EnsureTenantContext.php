<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::clear();

        $tenant = null;

        // 1. Resolve from authenticated user
        if ($request->user() && $request->user()->tenant_id) {
            $tenant = Tenant::find($request->user()->tenant_id);
        }

        // 2. Fallback: resolve from X-Tenant-ID header or X-Tenant-Slug
        if (!$tenant && $request->hasHeader('X-Tenant-ID')) {
            $tenant = Tenant::find($request->header('X-Tenant-ID'));
        } elseif (!$tenant && $request->hasHeader('X-Tenant-Slug')) {
            $tenant = Tenant::where('slug', $request->header('X-Tenant-Slug'))->first();
        }

        if ($tenant) {
            if ($tenant->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant subscription is inactive or suspended.',
                    'code' => 403,
                ], 403);
            }

            TenantContext::setTenant($tenant);

            // Configure Spatie Teams permissions context
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        }

        return $next($request);
    }
}
