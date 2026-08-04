<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of services catalog.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Service::query();

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        $services = $query->orderBy('name', 'asc')
            ->paginate($request->get('per_page', 20));

        return $this->successResponse($services, 'Services retrieved successfully.');
    }

    /**
     * Store a newly created service in catalog.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'duration_minutes' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'tax_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $service = Service::create($validated);

        AuditLogger::log(
            action: 'service_created',
            auditable: $service,
            newValues: $service->toArray()
        );

        return $this->successResponse($service, 'Service catalog item created.', 201);
    }

    /**
     * Display the specified service.
     */
    public function show(Service $service): JsonResponse
    {
        $service->load('employees');
        return $this->successResponse($service, 'Service details retrieved.');
    }

    /**
     * Update the specified service.
     */
    public function update(Request $request, Service $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'duration_minutes' => 'sometimes|required|integer|min:1',
            'price' => 'sometimes|required|numeric|min:0',
            'tax_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $oldValues = $service->toArray();
        $service->update($validated);

        AuditLogger::log(
            action: 'service_updated',
            auditable: $service,
            oldValues: $oldValues,
            newValues: $service->toArray()
        );

        return $this->successResponse($service, 'Service updated successfully.');
    }

    /**
     * Remove the specified service from storage.
     */
    public function destroy(Service $service): JsonResponse
    {
        $oldValues = $service->toArray();
        $service->delete();

        AuditLogger::log(
            action: 'service_deleted',
            auditable: $service,
            oldValues: $oldValues
        );

        return $this->successResponse(null, 'Service deleted successfully.');
    }
}
