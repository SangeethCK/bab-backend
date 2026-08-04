<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of customers with optional search by mobile, name, or customer ID.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('mobile', 'like', "%{$search}%")
                  ->orWhere('customer_code', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderBy('name', 'asc')
            ->paginate($request->get('per_page', 15));

        return $this->successResponse($customers, 'Customers retrieved successfully.');
    }

    /**
     * Store a newly created customer in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'customer_code' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'mobile' => 'required|string|max:50',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string',
        ]);

        if (empty($validated['customer_code'])) {
            $validated['customer_code'] = Customer::generateNextCustomerCode($tenantId);
        }

        $customer = Customer::create($validated);

        AuditLogger::log(
            action: 'customer_created',
            auditable: $customer,
            newValues: $customer->toArray()
        );

        return $this->successResponse($customer, 'Customer created successfully.', 201);
    }

    /**
     * Display the specified customer details.
     */
    public function show(Customer $customer): JsonResponse
    {
        return $this->successResponse($customer, 'Customer details retrieved.');
    }

    /**
     * Update the specified customer in storage.
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'customer_code' => 'nullable|string|max:50',
            'name' => 'sometimes|required|string|max:255',
            'mobile' => 'sometimes|required|string|max:50',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string',
        ]);

        $oldValues = $customer->toArray();
        $customer->update($validated);

        AuditLogger::log(
            action: 'customer_updated',
            auditable: $customer,
            oldValues: $oldValues,
            newValues: $customer->toArray()
        );

        return $this->successResponse($customer, 'Customer updated successfully.');
    }

    /**
     * Remove the specified customer from storage.
     */
    public function destroy(Customer $customer): JsonResponse
    {
        $oldValues = $customer->toArray();
        $customer->delete();

        AuditLogger::log(
            action: 'customer_deleted',
            auditable: $customer,
            oldValues: $oldValues
        );

        return $this->successResponse(null, 'Customer deleted successfully.');
    }

    /**
     * Get profile summary & history for a customer.
     */
    public function history(Customer $customer): JsonResponse
    {
        $auditLogs = AuditLog::where('auditable_type', Customer::class)
            ->where('auditable_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse([
            'customer' => $customer,
            'stats' => [
                'total_bookings' => 0,
                'total_spent' => 0.00,
                'joined_at' => $customer->created_at->toIso8601String(),
            ],
            'recent_activity' => $auditLogs,
        ], 'Customer profile history retrieved.');
    }
}
