<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Executive Dashboard KPI metrics.
     */
    public function getDashboardSummary(): array
    {
        $today = now()->format('Y-m-d');

        // 1. Today's Bookings KPI
        $todaysBookings = Booking::whereDate('start_time', $today)->get();
        $bookingStats = [
            'total' => $todaysBookings->count(),
            'scheduled' => $todaysBookings->where('status', 'scheduled')->count(),
            'checked_in' => $todaysBookings->where('status', 'checked_in')->count(),
            'in_progress' => $todaysBookings->where('status', 'in_progress')->count(),
            'completed' => $todaysBookings->where('status', 'completed')->count(),
            'cancelled' => $todaysBookings->where('status', 'cancelled')->count(),
        ];

        // 2. Today's Sales (Payments received today)
        $todaysSales = (float) Payment::whereDate('payment_date', $today)->sum('amount');

        // 3. Customer Total Outstanding Balance
        $totalOutstanding = (float) Invoice::whereIn('status', ['unpaid', 'partially_paid'])->sum('due_amount');

        // 4. Current Cash Balance (Cash Payments - Cash Expenses)
        $cashInTotal = (float) Payment::where('payment_method', 'cash')->sum('amount');
        $cashOutTotal = (float) Expense::where('payment_method', 'cash')->sum('amount');
        $currentCashBalance = round($cashInTotal - $cashOutTotal, 2);

        // 5. Active Employees Count
        $activeEmployeesCount = Employee::where('status', 'active')->count();

        // 6. Live / Recent Bookings list for Dashboard
        $recentBookings = Booking::with(['customer', 'employee', 'service'])
            ->orderBy('start_time', 'desc')
            ->limit(30)
            ->get();

        return [
            'today_date' => $today,
            'todays_bookings' => $bookingStats,
            'today_bookings' => $bookingStats['total'],
            'todays_sales' => $todaysSales,
            'total_sales' => $todaysSales,
            'total_customers' => Customer::count(),
            'total_outstanding_balance' => $totalOutstanding,
            'pending_amount' => $totalOutstanding,
            'current_cash_balance' => $currentCashBalance,
            'active_employees_count' => $activeEmployeesCount,
            'recent_bookings' => $recentBookings,
        ];
    }

    /**
     * Sales Report aggregated by date and payment method.
     */
    public function getSalesReport(string $startDate, string $endDate): array
    {
        $payments = Payment::with('customer:id,name', 'invoice:id,invoice_number')
            ->whereBetween('payment_date', ["{$startDate} 00:00:00", "{$endDate} 23:59:59"])
            ->orderBy('payment_date', 'desc')
            ->get();

        $byPaymentMethod = Payment::whereBetween('payment_date', ["{$startDate} 00:00:00", "{$endDate} 23:59:59"])
            ->select('payment_method', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as total_count'))
            ->groupBy('payment_method')
            ->get();

        $totalSales = (float) $payments->sum('amount');

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_sales' => $totalSales,
            'payment_method_breakdown' => $byPaymentMethod,
            'transactions' => $payments,
        ];
    }

    /**
     * Profit & Loss Statement (P&L).
     */
    public function getPnlReport(string $startDate, string $endDate): array
    {
        // Revenue from payments received
        $totalRevenue = (float) Payment::whereBetween('payment_date', ["{$startDate} 00:00:00", "{$endDate} 23:59:59"])
            ->sum('amount');

        // Total Expenses in range
        $expenses = Expense::whereBetween('expense_date', [$startDate, $endDate])->get();
        $totalExpenses = (float) $expenses->sum('amount');

        $expenseCategories = Expense::whereBetween('expense_date', [$startDate, $endDate])
            ->select('category', DB::raw('SUM(amount) as category_total'))
            ->groupBy('category')
            ->get();

        $netProfitLoss = round($totalRevenue - $totalExpenses, 2);

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'net_profit_loss' => $netProfitLoss,
            'is_profitable' => $netProfitLoss >= 0,
            'expense_breakdown' => $expenseCategories,
        ];
    }

    /**
     * Customer Outstanding Balance Ledger report.
     */
    public function getOutstandingReport(): array
    {
        $customersWithDues = Customer::whereHas('tenant')
            ->get()
            ->map(function ($customer) {
                $unpaidInvoices = Invoice::where('customer_id', $customer->id)
                    ->whereIn('status', ['unpaid', 'partially_paid'])
                    ->get(['id', 'invoice_number', 'due_amount', 'status', 'issue_date']);

                $dueTotal = (float) $unpaidInvoices->sum('due_amount');

                return [
                    'customer_id' => $customer->id,
                    'customer_code' => $customer->customer_code,
                    'customer_name' => $customer->name,
                    'mobile' => $customer->mobile,
                    'unpaid_invoices_count' => $unpaidInvoices->count(),
                    'total_due_amount' => $dueTotal,
                    'invoices' => $unpaidInvoices,
                ];
            })
            ->filter(fn ($item) => $item['total_due_amount'] > 0)
            ->values();

        $grandTotalOutstanding = (float) $customersWithDues->sum('total_due_amount');

        return [
            'grand_total_outstanding' => $grandTotalOutstanding,
            'total_customers_with_dues' => $customersWithDues->count(),
            'customer_ledger' => $customersWithDues,
        ];
    }
}
