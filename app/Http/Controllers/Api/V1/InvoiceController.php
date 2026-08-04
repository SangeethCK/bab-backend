<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\BillingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    use ApiResponse;

    protected BillingService $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    /**
     * Display a listing of invoices with status and customer filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with('customer', 'booking');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->filled('date')) {
            $query->whereDate('issue_date', $request->input('date'));
        }

        $invoices = $query->orderBy('issue_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->get('per_page', 15));

        return $this->successResponse($invoices, 'Invoices retrieved successfully.');
    }

    /**
     * Store a newly created invoice with line items.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'issue_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.service_id' => 'nullable|exists:services,id',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $invoice = $this->billingService->createInvoice($validated);
            return $this->successResponse($invoice, 'Invoice created successfully.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Display specified invoice details.
     */
    public function show(Invoice $invoice): JsonResponse
    {
        return $this->successResponse(
            $invoice->load('items', 'customer', 'booking', 'payments'),
            'Invoice details retrieved.'
        );
    }

    /**
     * Record a full or partial payment for an invoice.
     */
    public function recordPayment(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card,upi,bank_transfer,other',
            'reference_number' => 'nullable|string|max:255',
            'payment_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        try {
            $payment = $this->billingService->recordPayment($invoice, $validated);
            return $this->successResponse([
                'payment' => $payment,
                'invoice_status' => $invoice->fresh()->status,
                'paid_amount' => $invoice->fresh()->paid_amount,
                'due_amount' => $invoice->fresh()->due_amount,
            ], 'Payment recorded successfully.');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Download or stream PDF for invoice.
     */
    public function pdf(Invoice $invoice)
    {
        $pdf = $this->billingService->generateInvoicePdf($invoice);
        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }
}
