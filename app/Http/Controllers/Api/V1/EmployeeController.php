<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of employees with assigned service skills.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Employee::with('services');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('designation', 'like', "%{$search}%");
            });
        }

        $employees = $query->orderBy('first_name', 'asc')
            ->paginate($request->get('per_page', 15));

        return $this->successResponse($employees, 'Employees retrieved successfully.');
    }

    /**
     * Store a newly created employee record and assign skills.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'required|string|max:50',
            'designation' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive',
            'services' => 'nullable|array',
            'services.*.service_id' => 'required|exists:services,id',
            'services.*.custom_duration_minutes' => 'nullable|integer',
            'services.*.custom_price' => 'nullable|numeric',
        ]);

        $employee = Employee::create([
            'user_id' => $validated['user_id'] ?? null,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'],
            'designation' => $validated['designation'] ?? null,
            'status' => $validated['status'] ?? 'active',
        ]);

        if (!empty($validated['services'])) {
            $syncData = [];
            foreach ($validated['services'] as $srv) {
                $syncData[$srv['service_id']] = [
                    'custom_duration_minutes' => $srv['custom_duration_minutes'] ?? null,
                    'custom_price' => $srv['custom_price'] ?? null,
                ];
            }
            $employee->services()->sync($syncData);
        }

        AuditLogger::log(
            action: 'employee_created',
            auditable: $employee,
            newValues: $employee->load('services')->toArray()
        );

        return $this->successResponse($employee->load('services'), 'Employee record created.', 201);
    }

    /**
     * Display the specified employee details with skills.
     */
    public function show(Employee $employee): JsonResponse
    {
        return $this->successResponse($employee->load('services', 'user'), 'Employee details retrieved.');
    }

    /**
     * Update the specified employee record.
     */
    public function update(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'sometimes|required|string|max:50',
            'designation' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive',
        ]);

        $oldValues = $employee->toArray();
        $employee->update($validated);

        AuditLogger::log(
            action: 'employee_updated',
            auditable: $employee,
            oldValues: $oldValues,
            newValues: $employee->toArray()
        );

        return $this->successResponse($employee->load('services'), 'Employee details updated.');
    }

    /**
     * Remove the specified employee from storage.
     */
    public function destroy(Employee $employee): JsonResponse
    {
        $oldValues = $employee->toArray();
        $employee->delete();

        AuditLogger::log(
            action: 'employee_deleted',
            auditable: $employee,
            oldValues: $oldValues
        );

        return $this->successResponse(null, 'Employee deleted successfully.');
    }

    /**
     * Sync employee-to-service skills matrix.
     */
    public function syncSkills(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'services' => 'required|array',
            'services.*.service_id' => 'required|exists:services,id',
            'services.*.custom_duration_minutes' => 'nullable|integer',
            'services.*.custom_price' => 'nullable|numeric',
        ]);

        $syncData = [];
        foreach ($validated['services'] as $srv) {
            $syncData[$srv['service_id']] = [
                'custom_duration_minutes' => $srv['custom_duration_minutes'] ?? null,
                'custom_price' => $srv['custom_price'] ?? null,
            ];
        }

        $employee->services()->sync($syncData);

        AuditLogger::log(
            action: 'employee_skills_synced',
            auditable: $employee,
            newValues: ['assigned_service_ids' => array_keys($syncData)]
        );

        return $this->successResponse($employee->load('services'), 'Employee service skills updated.');
    }
}
