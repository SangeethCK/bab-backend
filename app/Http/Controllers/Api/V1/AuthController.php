<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * Authenticate user and issue Sanctum token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Query user without tenant scope filter to find account
        $user = TenantContext::bypass(function () use ($validated) {
            return User::with('tenant')->where('email', $validated['email'])->first();
        });

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return $this->errorResponse('Invalid email or password credentials.', 401);
        }

        if ($user->status !== 'active') {
            return $this->errorResponse('User account is inactive.', 403);
        }

        if ($user->tenant && $user->tenant->status !== 'active') {
            return $this->errorResponse('Tenant subscription is suspended or inactive.', 403);
        }

        // Set tenant context
        if ($user->tenant) {
            TenantContext::setTenant($user->tenant);
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant->id);
        }

        // Create API Token
        $token = $user->createToken('auth_token')->plainTextToken;

        // Log audit event
        AuditLogger::log(
            action: 'user_login',
            auditable: $user,
            tenantId: $user->tenant_id,
            userId: $user->id
        );

        return $this->successResponse([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'tenant' => $user->tenant ? [
                'id' => $user->tenant->id,
                'name' => $user->tenant->name,
                'slug' => $user->tenant->slug,
                'domain' => $user->tenant->domain,
                'status' => $user->tenant->status,
            ] : null,
        ], 'Login successful.');
    }

    /**
     * Revoke current Sanctum token.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            AuditLogger::log('user_logout', $user);
            $user->currentAccessToken()->delete();
        }

        return $this->successResponse(null, 'Successfully logged out.');
    }

    /**
     * Get authenticated user details and tenant context.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('tenant');

        if ($user->tenant) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant->id);
        }

        return $this->successResponse([
            'user' => [
                'id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'tenant' => $user->tenant ? [
                'id' => $user->tenant->id,
                'name' => $user->tenant->name,
                'slug' => $user->tenant->slug,
                'domain' => $user->tenant->domain,
                'status' => $user->tenant->status,
                'settings' => $user->tenant->settings,
            ] : null,
        ], 'Authenticated user context.');
    }

    /**
     * Request password reset link / token.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $user = TenantContext::bypass(fn() => User::where('email', $validated['email'])->first());

        if (!$user) {
            // Standard security response without revealing user presence
            return $this->successResponse(null, 'If your email is registered, you will receive password reset instructions.');
        }

        $token = Str::random(60);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        AuditLogger::log('password_reset_requested', $user, tenantId: $user->tenant_id, userId: $user->id);

        return $this->successResponse([
            'reset_token' => $token,
        ], 'Password reset token generated.');
    }

    /**
     * Reset user password using token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
        ]);

        $resetRecord = DB::table('password_reset_tokens')->where('email', $validated['email'])->first();

        if (!$resetRecord || !Hash::check($validated['token'], $resetRecord->token)) {
            return $this->errorResponse('Invalid or expired password reset token.', 400);
        }

        $user = TenantContext::bypass(fn() => User::where('email', $validated['email'])->first());

        if (!$user) {
            return $this->errorResponse('User not found.', 444);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

        AuditLogger::log('password_reset_completed', $user, tenantId: $user->tenant_id, userId: $user->id);

        return $this->successResponse(null, 'Password has been reset successfully.');
    }

    /**
     * Send OTP to customer via Email or Mobile.
     */
    public function sendCustomerOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_or_email' => 'required|string',
            'tenant_id' => 'nullable|integer',
        ]);

        $identifier = trim($validated['mobile_or_email']);
        $otp = '123456'; // Default OTP for testing & development

        \Illuminate\Support\Facades\Cache::put("customer_otp_{$identifier}", $otp, 600);

        return $this->successResponse([
            'identifier' => $identifier,
            'otp_hint' => '123456',
            'expires_in_seconds' => 600,
        ], "OTP sent successfully to {$identifier}. Use code 123456.");
    }

    /**
     * Verify OTP and authenticate customer.
     */
    public function verifyCustomerOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_or_email' => 'required|string',
            'otp' => 'required|string',
        ]);

        $identifier = trim($validated['mobile_or_email']);
        $submittedOtp = trim($validated['otp']);
        $cachedOtp = \Illuminate\Support\Facades\Cache::get("customer_otp_{$identifier}");

        if ($submittedOtp !== '123456' && $submittedOtp !== $cachedOtp) {
            return $this->errorResponse('Invalid or expired verification code.', 401);
        }

        // Resolve default tenant
        $tenant = Tenant::where('status', 'active')->first();
        if (!$tenant) {
            return $this->errorResponse('No active tenant found.', 400);
        }

        TenantContext::setTenant($tenant);

        // Find or create customer
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
        $customer = Customer::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($identifier, $isEmail) {
                if ($isEmail) {
                    $q->where('email', $identifier);
                } else {
                    $q->where('mobile', $identifier);
                }
            })->first();

        if (!$customer) {
            $customer = Customer::create([
                'tenant_id' => $tenant->id,
                'customer_code' => Customer::generateNextCustomerCode($tenant->id),
                'name' => $isEmail ? explode('@', $identifier)[0] : "Client {$identifier}",
                'mobile' => $isEmail ? '+1555000111' : $identifier,
                'email' => $isEmail ? $identifier : null,
            ]);
        }

        // Find or create user for customer login
        $userEmail = $customer->email ?? "cust_{$customer->id}@tenant.local";
        $user = User::where('email', $userEmail)->first();

        if (!$user) {
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $customer->name,
                'email' => $userEmail,
                'password' => Hash::make(Str::random(16)),
                'status' => 'active',
            ]);
        }

        $token = $user->createToken('customer_auth_token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'role' => 'customer',
            'user' => [
                'id' => $user->id,
                'tenant_id' => $tenant->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => 'customer',
            ],
            'customer' => [
                'id' => $customer->id,
                'customer_code' => $customer->customer_code,
                'name' => $customer->name,
                'mobile' => $customer->mobile,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
        ], 'Customer authenticated successfully.');
    }
}
