<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Middleware\EnsureTenantContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Version 1 (/api/v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // Public / Authentication & Onboarding Routes
    Route::post('/onboard', [OnboardingController::class, 'onboard']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

    // Authenticated Tenant Routes
    Route::middleware(['auth:sanctum', EnsureTenantContext::class])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/tenant', [TenantController::class, 'show']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        // Customer Catalog & History
        Route::get('/customers/{customer}/history', [CustomerController::class, 'history']);
        Route::apiResource('customers', CustomerController::class);

        // Services Catalog
        Route::apiResource('services', ServiceController::class);

        // Employee Master Records & Skills Matrix
        Route::post('/employees/{employee}/skills', [EmployeeController::class, 'syncSkills']);
        Route::apiResource('employees', EmployeeController::class);
    });
});
