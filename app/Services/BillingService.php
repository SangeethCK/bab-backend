<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

class BillingService
{
    /**
     * Create an invoice with line items and subtotal/tax/discount calculations.
     */
    public function createInvoice(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $tenantId = TenantContext::getTenantId();
            $customer = Customer::findOrFail($data['customer_id']);

            $issueDate = $data['issue_date'] ?? now()->format('Y-m-d');
            $dueDate = $data['due_date'] ?? null;
            $discountAmount = (float) ($data['discount_amount'] ?? 0.00);

            $subtotal = 0.00;
            $totalTaxAmount = 0.00;
            $itemsData = [];

            foreach ($data['items'] as $item) {
                $qty = (int) ($item['quantity'] ?? 1);
                $unitPrice = (float) $item['unit_price'];

                if (isset($item['service_id']) && $unitPrice <= 0) {
                    $service = Service::find($item['service_id']);
                    if ($service) {
                        $unitPrice = (float) $service->price;
                        $item['tax_percentage'] = $item['tax_percentage'] ?? $service->tax_percentage;
                    }
                }

                $taxPct = (float) ($item['tax_percentage'] ?? 0.00);
                $lineSubtotal = $qty * $unitPrice;
                $lineTax = round(($lineSubtotal * $taxPct) / 100, 2);
                $lineTotal = $lineSubtotal + $lineTax;

                $subtotal += $lineSubtotal;
                $totalTaxAmount += $lineTax;

                $itemsData[] = [
                    'service_id' => $item['service_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'tax_percentage' => $taxPct,
                    'tax_amount' => $lineTax,
                    'total_amount' => $lineTotal,
                ];
            }

            $totalAmount = max(0.00, round(($subtotal + $totalTaxAmount) - $discountAmount, 2));
            $invoiceNumber = Invoice::generateNextInvoiceNumber($tenantId);

            $invoice = Invoice::create([
                'tenant_id' => $tenantId,
                'invoice_number' => $invoiceNumber,
                'customer_id' => $customer->id,
                'booking_id' => $data['booking_id'] ?? null,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'status' => 'unpaid',
                'subtotal' => $subtotal,
                'tax_amount' => $totalTaxAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'paid_amount' => 0.00,
                'due_amount' => $totalAmount,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($itemsData as $lineItem) {
                $invoice->items()->create($lineItem);
            }

            AuditLogger::log(
                action: 'invoice_created',
                auditable: $invoice,
                newValues: $invoice->load('items')->toArray()
            );

            return $invoice->load('items', 'customer', 'booking');
        });
    }

    /**
     * Record a full or partial payment for an invoice.
     */
    public function recordPayment(Invoice $invoice, array $paymentData): Payment
    {
        return DB::transaction(function () use ($invoice, $paymentData) {
            $tenantId = TenantContext::getTenantId();
            $amount = (float) $paymentData['amount'];

            if ($amount <= 0) {
                throw new \InvalidArgumentException("Payment amount must be greater than 0.");
            }

            if ($amount > $invoice->due_amount) {
                throw new \InvalidArgumentException("Payment amount ({$amount}) exceeds outstanding invoice due amount ({$invoice->due_amount}).");
            }

            $paymentNumber = Payment::generateNextPaymentNumber($tenantId);

            $payment = Payment::create([
                'tenant_id' => $tenantId,
                'payment_number' => $paymentNumber,
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'amount' => $amount,
                'payment_method' => $paymentData['payment_method'] ?? 'cash',
                'reference_number' => $paymentData['reference_number'] ?? null,
                'payment_date' => $paymentData['payment_date'] ?? now(),
                'notes' => $paymentData['notes'] ?? null,
            ]);

            $newPaidAmount = round($invoice->paid_amount + $amount, 2);
            $newDueAmount = max(0.00, round($invoice->total_amount - $newPaidAmount, 2));

            $newStatus = 'unpaid';
            if ($newDueAmount <= 0) {
                $newStatus = 'paid';
            } elseif ($newPaidAmount > 0) {
                $newStatus = 'partially_paid';
            }

            $oldValues = $invoice->toArray();

            $invoice->update([
                'paid_amount' => $newPaidAmount,
                'due_amount' => $newDueAmount,
                'status' => $newStatus,
            ]);

            AuditLogger::log(
                action: 'payment_recorded',
                auditable: $payment,
                oldValues: $oldValues,
                newValues: ['payment' => $payment->toArray(), 'invoice' => $invoice->toArray()]
            );

            return $payment->load('invoice', 'customer');
        });
    }

    /**
     * Calculate total outstanding unpaid balance for a customer.
     */
    public function getCustomerOutstandingBalance(int $customerId): float
    {
        return (float) Invoice::where('customer_id', $customerId)
            ->whereIn('status', ['unpaid', 'partially_paid'])
            ->sum('due_amount');
    }

    /**
     * Generate HTML / DomPDF document stream for an invoice.
     */
    public function generateInvoicePdf(Invoice $invoice)
    {
        $invoice->load('items', 'customer', 'tenant', 'payments');

        $html = view('invoices.pdf', [
            'invoice' => $invoice,
            'tenant' => $invoice->tenant,
            'customer' => $invoice->customer,
            'items' => $invoice->items,
            'payments' => $invoice->payments,
        ])->render();

        return Pdf::loadHTML($html)->setPaper('a4', 'portrait');
    }
}
