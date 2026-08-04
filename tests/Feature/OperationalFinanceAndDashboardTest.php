<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalFinanceAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Grand Salon & Spa', 'slug' => 'grand-salon']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General Manager',
            'email' => 'gm@grandsalon.com',
            'password' => bcrypt('password'),
        ]);

        TenantContext::setTenant($this->tenant);
    }

    public function test_expense_crud(): void
    {
        $createRes = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'category' => 'Supplies',
                'amount' => 150.00,
                'payment_method' => 'cash',
                'expense_date' => now()->format('Y-m-d'),
                'vendor_name' => 'Beauty Supply Co.',
                'notes' => 'Shampoo bottles and towels',
            ]);

        $createRes->assertStatus(201);
        $expenseId = $createRes->json('data.id');

        $listRes = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/expenses');

        $listRes->assertStatus(200);
        $listRes->assertJsonCount(1, 'data.data');

        $deleteRes = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/expenses/{$expenseId}");

        $deleteRes->assertStatus(200);
        $this->assertSoftDeleted('expenses', ['id' => $expenseId]);
    }

    public function test_daily_closing_calculation_and_reconciliation(): void
    {
        $today = now()->format('Y-m-d');
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'customer_code' => 'CUST-0001',
            'name' => 'Jim Halpert',
            'mobile' => '555333444',
        ]);

        $invoice = Invoice::create([
            'tenant_id' => $this->tenant->id,
            'invoice_number' => 'INV-00001',
            'customer_id' => $customer->id,
            'issue_date' => $today,
            'status' => 'unpaid',
            'total_amount' => 100.00,
            'due_amount' => 100.00,
        ]);

        // Cash payment of $100
        Payment::create([
            'tenant_id' => $this->tenant->id,
            'payment_number' => 'PAY-00001',
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'payment_method' => 'cash',
            'payment_date' => now(),
        ]);

        // Cash expense of $30
        Expense::create([
            'tenant_id' => $this->tenant->id,
            'category' => 'Cleaning',
            'amount' => 30.00,
            'payment_method' => 'cash',
            'expense_date' => $today,
        ]);

        // 1. Calculate Daily Closing
        $calcRes = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/daily-closings/calculate', ['date' => $today]);

        $calcRes->assertStatus(200);
        $calcRes->assertJsonPath('data.cash_in', 100);
        $calcRes->assertJsonPath('data.cash_out', 30);
        $calcRes->assertJsonPath('data.expected_closing_cash', 70);

        // 2. Perform Closing
        $closeRes = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/daily-closings/close', [
                'closing_date' => $today,
                'actual_cash' => 70.00,
                'notes' => 'Balanced perfectly',
            ]);

        $closeRes->assertStatus(200);
        $closeRes->assertJsonPath('data.status', 'closed');
        $this->assertEquals(0.00, $closeRes->json('data.discrepancy'));
    }

    public function test_dashboard_summary_kpis(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Andy',
            'phone' => '555111222',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'today_date',
                'todays_bookings',
                'todays_sales',
                'total_outstanding_balance',
                'current_cash_balance',
                'active_employees_count',
            ],
        ]);
        $response->assertJsonPath('data.active_employees_count', 1);
    }

    public function test_financial_reports_sales_pnl_outstanding(): void
    {
        $today = now()->format('Y-m-d');

        // Sales Report
        $salesRes = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/reports/sales?start_date={$today}&end_date={$today}");
        $salesRes->assertStatus(200);
        $salesRes->assertJsonStructure(['data' => ['total_sales', 'payment_method_breakdown', 'transactions']]);

        // P&L Report
        $pnlRes = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/reports/pnl?start_date={$today}&end_date={$today}");
        $pnlRes->assertStatus(200);
        $pnlRes->assertJsonStructure(['data' => ['total_revenue', 'total_expenses', 'net_profit_loss', 'is_profitable']]);

        // Outstanding Report
        $outRes = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/outstanding');
        $outRes->assertStatus(200);
        $outRes->assertJsonStructure(['data' => ['grand_total_outstanding', 'customer_ledger']]);
    }
}
