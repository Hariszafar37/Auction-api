<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; }
        .header { background: #1e293b; color: #fff; padding: 24px 32px; display: flex; justify-content: space-between; }
        .header h1 { font-size: 22px; font-weight: 700; letter-spacing: 1px; }
        .header .invoice-meta { text-align: right; }
        .header .invoice-meta p { margin: 2px 0; font-size: 11px; }
        .header .invoice-meta .number { font-size: 16px; font-weight: 600; color: #f59e0b; }
        .section { padding: 20px 32px; }
        .section-title { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; margin-bottom: 8px; }
        .grid-2 { display: flex; gap: 32px; }
        .grid-2 > div { flex: 1; }
        .info-block p { margin: 3px 0; font-size: 12px; }
        .info-block strong { color: #1e293b; }
        .vehicle-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; margin: 12px 0; }
        .vehicle-box h3 { font-size: 14px; font-weight: 700; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        table thead tr { background: #f1f5f9; }
        table thead th { padding: 8px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; }
        table tbody tr { border-bottom: 1px solid #f1f5f9; }
        table tbody td { padding: 10px 12px; font-size: 12px; }
        .amount { text-align: right; }
        .totals-section { padding: 8px 32px 24px; }
        .totals-table { width: 320px; margin-left: auto; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
        .totals-table tr td { padding: 8px 16px; font-size: 12px; }
        .totals-table tr:nth-child(odd) { background: #f8fafc; }
        .totals-table .total-row td { font-weight: 700; font-size: 14px; background: #1e293b; color: #fff; }
        .totals-table .balance-row td { font-weight: 700; background: #f59e0b; color: #1e293b; }
        .status-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-pending { background: #fef9c3; color: #713f12; }
        .status-partial { background: #dbeafe; color: #1e40af; }
        .footer { margin-top: 32px; border-top: 1px solid #e2e8f0; padding: 16px 32px; font-size: 10px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>

<div class="header">
    <div>
        <h1>AUTO AUCTION</h1>
        <p style="font-size:11px; color:#94a3b8; margin-top:4px;">INVOICE</p>
    </div>
    <div class="invoice-meta">
        <p class="number">{{ $invoice->invoice_number }}</p>
        <p>Date: {{ $invoice->created_at->format('M d, Y') }}</p>
        @if($invoice->due_at)
        <p>Due: {{ $invoice->due_at->format('M d, Y') }}</p>
        @endif
        <p style="margin-top:6px;">
            <span class="status-badge status-{{ $invoice->status->value }}">
                {{ $invoice->status->label() }}
            </span>
        </p>
    </div>
</div>

<div class="section">
    <div class="grid-2">
        <div class="info-block">
            <p class="section-title">Bill To</p>
            <p><strong>{{ $invoice->buyer->full_name }}</strong></p>
            <p>{{ $invoice->buyer->email }}</p>
        </div>
        <div class="info-block">
            <p class="section-title">Auction</p>
            <p><strong>{{ $invoice->auction->title }}</strong></p>
            <p>{{ $invoice->auction->location }}</p>
            <p>Lot #{{ $invoice->lot->lot_number }}</p>
        </div>
    </div>

    <div class="vehicle-box">
        <p class="section-title">Vehicle</p>
        <h3>{{ $invoice->vehicle->year }} {{ $invoice->vehicle->make }} {{ $invoice->vehicle->model }}
            @if($invoice->vehicle->trim) {{ $invoice->vehicle->trim }}@endif</h3>
        <p style="color:#64748b; font-size:11px; margin-top:4px;">VIN: {{ $invoice->vehicle->vin }}</p>
    </div>
</div>

<div class="section" style="padding-top:0;">
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Sale Price (Winning Bid)</td>
                <td class="amount">${{ number_format($invoice->sale_price, 2) }}</td>
            </tr>
            @if((float)$invoice->buyer_fee_amount > 0)
            <tr>
                <td>Buyer Fee</td>
                <td class="amount">${{ number_format($invoice->buyer_fee_amount, 2) }}</td>
            </tr>
            @endif
            @if((float)$invoice->tax_amount > 0)
            <tr>
                <td>Sales Tax</td>
                <td class="amount">${{ number_format($invoice->tax_amount, 2) }}</td>
            </tr>
            @endif
            @if((float)$invoice->tags_amount > 0)
            <tr>
                <td>Tags / Title Fees</td>
                <td class="amount">${{ number_format($invoice->tags_amount, 2) }}</td>
            </tr>
            @endif
            @if($invoice->storage_days > 0)
            <tr>
                <td>Storage Fee ({{ $invoice->storage_days }} day{{ $invoice->storage_days !== 1 ? 's' : '' }} × ${{ number_format($invoice->storage_fee_amount / max($invoice->storage_days,1), 2) }}/day)</td>
                <td class="amount">${{ number_format($invoice->storage_fee_amount, 2) }}</td>
            </tr>
            @endif
        </tbody>
    </table>
</div>

<div class="totals-section">
    <table class="totals-table">
        <tr>
            <td>Subtotal</td>
            <td style="text-align:right;">${{ number_format($invoice->total_amount, 2) }}</td>
        </tr>
        @if((float)$invoice->deposit_amount > 0)
        <tr>
            <td>Deposit Required</td>
            <td style="text-align:right;">${{ number_format($invoice->deposit_amount, 2) }}</td>
        </tr>
        @endif
        @if((float)$invoice->amount_paid > 0)
        <tr>
            <td>Amount Paid</td>
            <td style="text-align:right; color:#16a34a;">-${{ number_format($invoice->amount_paid, 2) }}</td>
        </tr>
        @endif
        <tr class="total-row">
            <td>TOTAL DUE</td>
            <td style="text-align:right;">${{ number_format($invoice->total_amount, 2) }}</td>
        </tr>
        @if((float)$invoice->balance_due > 0)
        <tr class="balance-row">
            <td>BALANCE DUE</td>
            <td style="text-align:right;">${{ number_format($invoice->balance_due, 2) }}</td>
        </tr>
        @endif
    </table>
</div>

@if($invoice->payments->isNotEmpty())
<div class="section" style="padding-top:0;">
    <p class="section-title">Payment History</p>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Method</th>
                <th>Reference</th>
                <th class="amount">Amount</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->payments as $payment)
            <tr>
                <td>{{ $payment->processed_at?->format('M d, Y') ?? $payment->created_at->format('M d, Y') }}</td>
                <td>{{ $payment->method->label() }}</td>
                <td>{{ $payment->reference ?? '—' }}</td>
                <td class="amount">${{ number_format($payment->amount, 2) }}</td>
                <td>{{ ucfirst($payment->status) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

<div class="footer">
    <p>This document is an official invoice. Please retain for your records.</p>
    <p style="margin-top:4px;">Questions? Contact us at support@example.com</p>
</div>

</body>
</html>
