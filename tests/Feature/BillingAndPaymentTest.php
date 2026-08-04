<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingAndPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Customer $customer;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Lux Spa', 'slug' => 'lux-spa']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Accountant User',
            'email' => 'accounts@luxspa.com',
            'password' => bcrypt('password'),
        ]);

        TenantContext::setTenant($this->tenant);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'customer_code' => 'CUST-0001',
            'name' => 'Pam Beesly',
            'mobile' => '555777888',
        ]);

        $this->service = Service::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Swedish Massage',
            'duration_minutes' => 60,
            'price' => 100.00,
            'tax_percentage' => 10.00,
        ]);
    }

    public function test_invoice_creation_with_line_items_and_sequential_numbering(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'issue_date' => now()->format('Y-m-d'),
            'discount_amount' => 10.00,
            'items' => [
                [
                    'service_id' => $this->service->id,
                    'description' => '60 Min Swedish Massage',
                    'quantity' => 2,
                    'unit_price' => 100.00,
                    'tax_percentage' => 10.00,
                ],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.invoice_number', 'INV-00001');
        $response->assertJsonPath('data.subtotal', '200.00');
        $response->assertJsonPath('data.tax_amount', '20.00');
        $response->assertJsonPath('data.discount_amount', '10.00');
        $response->assertJsonPath('data.total_amount', '210.00');
        $response->assertJsonPath('data.due_amount', '210.00');
        $response->assertJsonPath('data.status', 'unpaid');
    }

    public function test_full_and_partial_payment_lifecycle(): void
    {
        // 1. Create Invoice for $100 total
        $invoice = Invoice::create([
            'tenant_id' => $this->tenant->id,
            'invoice_number' => 'INV-00002',
            'customer_id' => $this->customer->id,
            'issue_date' => now()->format('Y-m-d'),
            'status' => 'unpaid',
            'subtotal' => 100.00,
            'tax_amount' => 0.00,
            'discount_amount' => 0.00,
            'total_amount' => 100.00,
            'paid_amount' => 0.00,
            'due_amount' => 100.00,
        ]);

        // 2. Record Partial Payment of $40
        $pay1 = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
                'amount' => 40.00,
                'payment_method' => 'card',
                'reference_number' => 'REF-1001',
            ]);

        $pay1->assertStatus(200);
        $pay1->assertJsonPath('data.invoice_status', 'partially_paid');
        $pay1->assertJsonPath('data.paid_amount', '40.00');
        $pay1->assertJsonPath('data.due_amount', '60.00');

        // 3. Record Final Payment of $60
        $pay2 = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
                'amount' => 60.00,
                'payment_method' => 'upi',
            ]);

        $pay2->assertStatus(200);
        $pay2->assertJsonPath('data.invoice_status', 'paid');
        $pay2->assertJsonPath('data.paid_amount', '100.00');
        $pay2->assertJsonPath('data.due_amount', '0.00');
    }

    public function test_customer_outstanding_balance(): void
    {
        // Unpaid invoice $150
        Invoice::create([
            'tenant_id' => $this->tenant->id,
            'invoice_number' => 'INV-00003',
            'customer_id' => $this->customer->id,
            'issue_date' => now()->format('Y-m-d'),
            'status' => 'unpaid',
            'subtotal' => 150.00,
            'total_amount' => 150.00,
            'paid_amount' => 0.00,
            'due_amount' => 150.00,
        ]);

        // Partially paid invoice ($100 total, $30 due)
        Invoice::create([
            'tenant_id' => $this->tenant->id,
            'invoice_number' => 'INV-00004',
            'customer_id' => $this->customer->id,
            'issue_date' => now()->format('Y-m-d'),
            'status' => 'partially_paid',
            'subtotal' => 100.00,
            'total_amount' => 100.00,
            'paid_amount' => 70.00,
            'due_amount' => 30.00,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/customers/{$this->customer->id}/outstanding-balance");

        $response->assertStatus(200);
        $this->assertEquals(180.00, $response->json('data.outstanding_balance'));
    }

    public function test_invoice_pdf_generation(): void
    {
        $invoice = Invoice::create([
            'tenant_id' => $this->tenant->id,
            'invoice_number' => 'INV-00005',
            'customer_id' => $this->customer->id,
            'issue_date' => now()->format('Y-m-d'),
            'status' => 'unpaid',
            'subtotal' => 50.00,
            'total_amount' => 50.00,
            'due_amount' => 50.00,
        ]);

        $invoice->items()->create([
            'description' => 'Test Service Line Item',
            'quantity' => 1,
            'unit_price' => 50.00,
            'total_amount' => 50.00,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoice->id}/pdf");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }
}
