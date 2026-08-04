<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of expenses.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Expense::with('user:id,name');

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }

        if ($request->filled('date')) {
            $query->whereDate('expense_date', $request->input('date'));
        }

        $expenses = $query->orderBy('expense_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->get('per_page', 15));

        return $this->successResponse($expenses, 'Expenses retrieved successfully.');
    }

    /**
     * Store a newly created expense record.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card,upi,bank_transfer,other',
            'expense_date' => 'required|date',
            'vendor_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $validated['user_id'] = $request->user()?->id;

        $expense = Expense::create($validated);

        AuditLogger::log(
            action: 'expense_created',
            auditable: $expense,
            newValues: $expense->toArray()
        );

        return $this->successResponse($expense, 'Expense recorded successfully.', 201);
    }

    /**
     * Display the specified expense details.
     */
    public function show(Expense $expense): JsonResponse
    {
        return $this->successResponse($expense->load('user:id,name'), 'Expense details retrieved.');
    }

    /**
     * Update the specified expense record.
     */
    public function update(Request $request, Expense $expense): JsonResponse
    {
        $validated = $request->validate([
            'category' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0.01',
            'payment_method' => 'sometimes|required|in:cash,card,upi,bank_transfer,other',
            'expense_date' => 'sometimes|required|date',
            'vendor_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $oldValues = $expense->toArray();
        $expense->update($validated);

        AuditLogger::log(
            action: 'expense_updated',
            auditable: $expense,
            oldValues: $oldValues,
            newValues: $expense->toArray()
        );

        return $this->successResponse($expense, 'Expense updated successfully.');
    }

    /**
     * Remove the specified expense from storage.
     */
    public function destroy(Expense $expense): JsonResponse
    {
        $oldValues = $expense->toArray();
        $expense->delete();

        AuditLogger::log(
            action: 'expense_deleted',
            auditable: $expense,
            oldValues: $oldValues
        );

        return $this->successResponse(null, 'Expense deleted successfully.');
    }
}
