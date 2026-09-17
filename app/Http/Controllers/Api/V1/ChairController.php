<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Chair;
use App\Services\ChairService;
use App\Services\TenantContext;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChairController extends Controller
{
    use ApiResponse;

    protected ChairService $chairService;

    public function __construct(ChairService $chairService)
    {
        $this->chairService = $chairService;
    }

    /**
     * Get real-time POS visual grid status for all chairs.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $date = $request->query('date');
        $dashboard = $this->chairService->getLiveDashboard($date);

        return $this->successResponse($dashboard, 'POS chair visual grid dashboard retrieved.');
    }

    /**
     * List all chairs for the tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Chair::with('employee:id,first_name,last_name,designation,status');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $chairs = $query->orderBy('sort_order', 'asc')
            ->orderBy('chair_number', 'asc')
            ->get();

        return $this->successResponse($chairs, 'Chairs retrieved successfully.');
    }

    /**
     * Store a new chair.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'chair_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('chairs')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId)->whereNull('deleted_at');
                }),
            ],
            'employee_id' => 'nullable|exists:employees,id',
            'status' => 'nullable|in:available,maintenance,inactive',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if (empty($validated['chair_number'])) {
            $validated['chair_number'] = Chair::generateNextChairNumber($tenantId);
        }

        $validated['status'] = $validated['status'] ?? 'available';

        $chair = Chair::create($validated);

        return $this->successResponse($chair->load('employee'), 'Chair created successfully.', 201);
    }

    /**
     * Display a single chair.
     */
    public function show(Chair $chair): JsonResponse
    {
        return $this->successResponse($chair->load('employee'), 'Chair details retrieved.');
    }

    /**
     * Update an existing chair.
     */
    public function update(Request $request, Chair $chair): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'chair_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('chairs')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId)->whereNull('deleted_at');
                })->ignore($chair->id),
            ],
            'employee_id' => 'nullable|exists:employees,id',
            'status' => 'sometimes|in:available,maintenance,inactive',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $chair->update($validated);

        return $this->successResponse($chair->load('employee'), 'Chair updated successfully.');
    }

    /**
     * Delete a chair.
     */
    public function destroy(Chair $chair): JsonResponse
    {
        $chair->delete();

        return $this->successResponse(null, 'Chair deleted successfully.');
    }

    /**
     * Assign or unassign an employee to a chair.
     */
    public function assignEmployee(Request $request, Chair $chair): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $chair = $this->chairService->assignEmployee($chair, $validated['employee_id'] ?? null);

        return $this->successResponse($chair, 'Chair employee assignment updated successfully.');
    }
}
