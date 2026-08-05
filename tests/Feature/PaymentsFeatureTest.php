<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentsFeatureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Customer $customer;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Royal Cuts', 'slug' => 'royal', 'status' => 'active']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier',
            'email' => 'cashier@royal.com',
            'password' => bcrypt('password123'),
        ]);

        TenantContext::setTenant($this->tenant);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'customer_code' => 'CUST-0001',
            'name' => 'Robert Downey',
            'mobile' => '987654321',
        ]);

        $this->invoice = Invoice::create([
            'tenant_id' => $this->tenant->id,
            'invoice_number' => 'INV-2026-001',
            'customer_id' => $this->customer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'unpaid',
            'subtotal' => 100.00,
            'tax_amount' => 0.00,
            'discount_amount' => 0.00,
            'total_amount' => 100.00,
            'paid_amount' => 0.00,
            'due_amount' => 100.00,
        ]);
    }

    public function test_recording_partial_payment_updates_balance(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
                'amount' => 40.00,
                'payment_method' => 'cash',
                'notes' => 'Partial cash payment',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.paid_amount', '40.00');
        $response->assertJsonPath('data.due_amount', '60.00');
        $response->assertJsonPath('data.invoice_status', 'partially_paid');
    }

    public function test_full_payment_marks_invoice_as_paid(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
                'amount' => 100.00,
                'payment_method' => 'card',
                'notes' => 'Full card payment',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.paid_amount', '100.00');
        $response->assertJsonPath('data.due_amount', '0.00');
        $response->assertJsonPath('data.invoice_status', 'paid');
    }

    public function test_overpaying_already_paid_invoice_returns_validation_error(): void
    {
        // First pay full amount
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
                'amount' => 100.00,
                'payment_method' => 'card',
            ]);

        // Attempting to record another payment on a zero balance invoice
        $overpayResponse = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
                'amount' => 20.00,
                'payment_method' => 'cash',
            ]);

        $overpayResponse->assertStatus(422);
    }
}
