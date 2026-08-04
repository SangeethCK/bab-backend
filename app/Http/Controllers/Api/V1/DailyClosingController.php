<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DailyClosing;
use App\Models\Expense;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DailyClosingController extends Controller
{
    use ApiResponse;

    /**
     * Listing of daily closings history.
     */
    public function index(Request $request): JsonResponse
    {
        $closings = DailyClosing::with('closedBy:id,name')
            ->orderBy('closing_date', 'desc')
            ->paginate($request->get('per_page', 15));

        return $this->successResponse($closings, 'Daily closings retrieved successfully.');
    }

    /**
     * Calculate draft daily cash metrics for a given date.
     */
    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        $date = $validated['date'];
        $tenantId = TenantContext::getTenantId();

        // Get previous day's closing cash as opening cash
        $previousClosing = DailyClosing::where('closing_date', '<', $date)
            ->orderBy('closing_date', 'desc')
            ->first();

        $openingCash = $previousClosing ? (float) $previousClosing->closing_cash : 0.00;

        // Cash In (Cash payments on that date)
        $cashIn = (float) Payment::whereDate('payment_date', $date)
            ->where('payment_method', 'cash')
            ->sum('amount');

        // Cash Out (Cash expenses on that date)
        $cashOut = (float) Expense::whereDate('expense_date', $date)
            ->where('payment_method', 'cash')
            ->sum('amount');

        $expectedClosingCash = round(($openingCash + $cashIn) - $cashOut, 2);

        $existingClosing = DailyClosing::where('closing_date', $date)->first();

        return $this->successResponse([
            'closing_date' => $date,
            'opening_cash' => $openingCash,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_closing_cash' => $expectedClosingCash,
            'is_already_closed' => $existingClosing?->status === 'closed',
            'existing_closing' => $existingClosing,
        ], 'Daily closing metrics calculated.');
    }

    /**
     * Finalize and record daily cash closing.
     */
    public function close(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'closing_date' => 'required|date_format:Y-m-d',
            'opening_cash' => 'nullable|numeric|min:0',
            'actual_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $date = $validated['closing_date'];
        $tenantId = TenantContext::getTenantId();

        return DB::transaction(function () use ($validated, $date, $tenantId, $request) {
            $previousClosing = DailyClosing::where('closing_date', '<', $date)
                ->orderBy('closing_date', 'desc')
                ->first();

            $openingCash = isset($validated['opening_cash'])
                ? (float) $validated['opening_cash']
                : ($previousClosing ? (float) $previousClosing->closing_cash : 0.00);

            $cashIn = (float) Payment::whereDate('payment_date', $date)
                ->where('payment_method', 'cash')
                ->sum('amount');

            $cashOut = (float) Expense::whereDate('expense_date', $date)
                ->where('payment_method', 'cash')
                ->sum('amount');

            $closingCash = round(($openingCash + $cashIn) - $cashOut, 2);
            $actualCash = (float) $validated['actual_cash'];
            $discrepancy = round($actualCash - $closingCash, 2);

            $dailyClosing = DailyClosing::updateOrCreate(
                ['tenant_id' => $tenantId, 'closing_date' => $date],
                [
                    'opening_cash' => $openingCash,
                    'cash_in' => $cashIn,
                    'cash_out' => $cashOut,
                    'closing_cash' => $closingCash,
                    'actual_cash' => $actualCash,
                    'discrepancy' => $discrepancy,
                    'status' => 'closed',
                    'closed_by' => $request->user()?->id,
                    'notes' => $validated['notes'] ?? null,
                ]
            );

            AuditLogger::log(
                action: 'daily_closing_completed',
                auditable: $dailyClosing,
                newValues: $dailyClosing->toArray()
            );

            return $this->successResponse($dailyClosing->load('closedBy:id,name'), 'Daily cash closing completed successfully.');
        });
    }
}
