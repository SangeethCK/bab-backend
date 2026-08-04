<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Get executive dashboard KPIs summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $metrics = $this->reportService->getDashboardSummary();
        return $this->successResponse($metrics, 'Executive dashboard metrics retrieved.');
    }
}
