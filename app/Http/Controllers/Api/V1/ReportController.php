<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponse;

    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Sales report endpoint.
     */
    public function sales(Request $request): JsonResponse
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        $report = $this->reportService->getSalesReport($startDate, $endDate);
        return $this->successResponse($report, 'Sales report generated.');
    }

    /**
     * Profit & Loss (P&L) report endpoint.
     */
    public function pnl(Request $request): JsonResponse
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        $report = $this->reportService->getPnlReport($startDate, $endDate);
        return $this->successResponse($report, 'P&L report generated.');
    }

    /**
     * Customer Outstanding Balance Ledger report endpoint.
     */
    public function outstanding(Request $request): JsonResponse
    {
        $report = $this->reportService->getOutstandingReport();
        return $this->successResponse($report, 'Outstanding balances report generated.');
    }
}
