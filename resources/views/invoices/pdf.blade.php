<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 13px; color: #333; margin: 0; padding: 20px; }
        .header { margin-bottom: 30px; }
        .company-title { font-size: 24px; font-weight: bold; color: #1e293b; }
        .invoice-title { font-size: 20px; font-weight: bold; text-align: right; color: #4f46e5; }
        .info-table { width: 100%; margin-bottom: 25px; border-collapse: collapse; }
        .info-table td { vertical-align: top; padding: 5px; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .items-table th { background-color: #f1f5f9; color: #334155; padding: 10px; border-bottom: 2px solid #cbd5e1; text-align: left; }
        .items-table td { padding: 10px; border-bottom: 1px solid #e2e8f0; }
        .totals-table { width: 40%; float: right; border-collapse: collapse; }
        .totals-table td { padding: 6px 10px; }
        .totals-table .total-row { font-weight: bold; font-size: 15px; border-top: 2px solid #1e293b; }
        .status-badge { display: inline-block; padding: 4px 10px; font-size: 11px; font-weight: bold; text-transform: uppercase; border-radius: 4px; }
        .status-paid { background-color: #dcfce7; color: #166534; }
        .status-unpaid { background-color: #fee2e2; color: #991b1b; }
        .status-partially_paid { background-color: #fef3c7; color: #92400e; }
        .clear { clear: both; }
        .footer { margin-top: 50px; text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 15px; }
    </style>
</head>
<body>
    <table class="info-table">
        <tr>
            <td width="50%">
                <div class="company-title">{{ $tenant->name ?? 'Business SaaS' }}</div>
                <div>{{ $tenant->domain ?? '' }}</div>
            </td>
            <td width="50%" style="text-align: right;">
                <div class="invoice-title">INVOICE</div>
                <div><strong>Invoice #:</strong> {{ $invoice->invoice_number }}</div>
                <div><strong>Issue Date:</strong> {{ $invoice->issue_date ? $invoice->issue_date->format('M d, Y') : '' }}</div>
                <div>
                    <strong>Status:</strong>
                    <span class="status-badge status-{{ $invoice->status }}">{{ str_replace('_', ' ', $invoice->status) }}</span>
                </div>
            </td>
        </tr>
    </table>

    <table class="info-table">
        <tr>
            <td>
                <strong>Billed To:</strong><br>
                {{ $customer->name }}<br>
                Phone: {{ $customer->mobile }}<br>
                @if($customer->email) Email: {{ $customer->email }}<br> @endif
                Customer Code: {{ $customer->customer_code }}
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align: center;">Qty</th>
                <th style="text-align: right;">Unit Price</th>
                <th style="text-align: right;">Tax</th>
                <th style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td style="text-align: center;">{{ $item->quantity }}</td>
                    <td style="text-align: right;">${{ number_format($item->unit_price, 2) }}</td>
                    <td style="text-align: right;">${{ number_format($item->tax_amount, 2) }}</td>
                    <td style="text-align: right;">${{ number_format($item->total_amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td>Subtotal:</td>
            <td style="text-align: right;">${{ number_format($invoice->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>Tax Total:</td>
            <td style="text-align: right;">${{ number_format($invoice->tax_amount, 2) }}</td>
        </tr>
        @if($invoice->discount_amount > 0)
        <tr>
            <td>Discount:</td>
            <td style="text-align: right;">-${{ number_format($invoice->discount_amount, 2) }}</td>
        </tr>
        @endif
        <tr class="total-row">
            <td>Grand Total:</td>
            <td style="text-align: right;">${{ number_format($invoice->total_amount, 2) }}</td>
        </tr>
        <tr>
            <td>Paid Amount:</td>
            <td style="text-align: right;">${{ number_format($invoice->paid_amount, 2) }}</td>
        </tr>
        <tr>
            <td><strong>Balance Due:</strong></td>
            <td style="text-align: right; color: #dc2626;"><strong>${{ number_format($invoice->due_amount, 2) }}</strong></td>
        </tr>
    </table>

    <div class="clear"></div>

    <div class="footer">
        Thank you for your business! If you have any questions about this invoice, please contact support.
    </div>
</body>
</html>
