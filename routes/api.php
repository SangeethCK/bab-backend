<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DailyClosingController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\ReportController;
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
    Route::post('/auth/customer/send-otp', [AuthController::class, 'sendCustomerOtp']);
    Route::post('/auth/customer/verify-otp', [AuthController::class, 'verifyCustomerOtp']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

    // Public Tenant Catalog & Booking Routes (accessible to guest customers)
    Route::middleware([EnsureTenantContext::class])->group(function () {
        Route::get('/services', [ServiceController::class, 'index']);
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::get('/bookings/available-slots', [BookingController::class, 'availableSlots']);
        Route::post('/bookings', [BookingController::class, 'store']);
    });

    // Authenticated Admin & Management Routes
    Route::middleware(['auth:sanctum', EnsureTenantContext::class])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/tenant', [TenantController::class, 'show']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        // Customer Catalog & History
        Route::get('/customers/{customer}/history', [CustomerController::class, 'history']);
        Route::get('/customers/{customer}/outstanding-balance', [CustomerController::class, 'outstandingBalance']);
        Route::apiResource('customers', CustomerController::class);

        // Services Catalog Management
        Route::post('/services', [ServiceController::class, 'store']);
        Route::get('/services/{service}', [ServiceController::class, 'show']);
        Route::put('/services/{service}', [ServiceController::class, 'update']);
        Route::delete('/services/{service}', [ServiceController::class, 'destroy']);

        // Employee Master Records & Skills Matrix Management
        Route::post('/employees/{employee}/skills', [EmployeeController::class, 'syncSkills']);
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
        Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);

        // Booking Engine & Calendar Endpoints
        Route::get('/bookings/calendar', [BookingController::class, 'calendar']);
        Route::patch('/bookings/{booking}/reschedule', [BookingController::class, 'reschedule']);
        Route::patch('/bookings/{booking}/status', [BookingController::class, 'updateStatus']);
        Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::get('/bookings/{booking}', [BookingController::class, 'show']);
        Route::put('/bookings/{booking}', [BookingController::class, 'update']);
        Route::delete('/bookings/{booking}', [BookingController::class, 'destroy']);

        // Billing and Payment Endpoints
        Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment']);
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
        Route::apiResource('invoices', InvoiceController::class);

        // Expenses CRUD
        Route::apiResource('expenses', ExpenseController::class);

        // Daily Closings & Reconciliation
        Route::get('/daily-closings', [DailyClosingController::class, 'index']);
        Route::post('/daily-closings/calculate', [DailyClosingController::class, 'calculate']);
        Route::post('/daily-closings/close', [DailyClosingController::class, 'close']);

        // Dashboard Summary KPIs
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

        // Financial Reports
        Route::get('/reports/sales', [ReportController::class, 'sales']);
        Route::get('/reports/pnl', [ReportController::class, 'pnl']);
        Route::get('/reports/outstanding', [ReportController::class, 'outstanding']);
    });
});
