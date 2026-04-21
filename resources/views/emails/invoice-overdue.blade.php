<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Overdue Invoice {{ $invoice->invoice_number }}</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f4f4f5; margin: 0; padding: 0; }
    .wrapper { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .header { background: #7f1d1d; padding: 32px 40px; }
    .header h1 { color: #fca5a5; margin: 0; font-size: 22px; }
    .header p { color: #fecaca; margin: 4px 0 0; font-size: 14px; }
    .body { padding: 32px 40px; }
    .overdue-badge { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 16px 20px; margin-bottom: 20px; }
    .overdue-badge .days { font-size: 28px; font-weight: bold; color: #991b1b; }
    .overdue-badge .label { font-size: 13px; color: #b91c1c; margin-top: 2px; }
    .invoice-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 20px 24px; margin-bottom: 20px; }
    .invoice-box h2 { margin: 0 0 12px; font-size: 16px; color: #0f172a; }
    .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; color: #475569; }
    .row:last-child { border-bottom: none; }
    .row.total { font-weight: bold; color: #0f172a; font-size: 16px; }
    .storage-warning { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; padding: 12px 16px; font-size: 13px; color: #92400e; margin-bottom: 24px; }
    .cta { text-align: center; margin: 28px 0; }
    .cta a { background: #dc2626; color: #fff; text-decoration: none; padding: 14px 36px; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block; }
    .footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 40px; font-size: 12px; color: #94a3b8; text-align: center; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>⚠️ Invoice Overdue</h1>
      <p>Immediate payment required</p>
    </div>
    <div class="body">
      <p style="color:#334155;font-size:15px;">
        Hello {{ $invoice->buyer->name ?? 'Valued Customer' }},
      </p>
      <p style="color:#475569;font-size:14px;">
        Your invoice for the vehicle below is now past due. Please pay immediately to avoid additional fees.
      </p>

      @php
        $daysPastDue = $invoice->due_at ? (int) $invoice->due_at->diffInDays(now()) : 0;
      @endphp

      <div class="overdue-badge">
        <div class="days">{{ $daysPastDue }} day{{ $daysPastDue !== 1 ? 's' : '' }} past due</div>
        <div class="label">Due {{ $invoice->due_at?->format('F j, Y') }}</div>
      </div>

      <div class="invoice-box">
        <h2>Invoice {{ $invoice->invoice_number }}</h2>
        @if($invoice->vehicle)
        <div class="row">
          <span>Vehicle</span>
          <span>{{ $invoice->vehicle->year }} {{ $invoice->vehicle->make }} {{ $invoice->vehicle->model }}</span>
        </div>
        @endif
        @if($invoice->lot)
        <div class="row">
          <span>Lot #</span>
          <span>{{ $invoice->lot->lot_number }}</span>
        </div>
        @endif
        <div class="row">
          <span>Sale Price</span>
          <span>${{ number_format($invoice->sale_price, 2) }}</span>
        </div>
        @if((float)$invoice->buyer_fee_amount > 0)
        <div class="row">
          <span>Buyer Fee</span>
          <span>${{ number_format($invoice->buyer_fee_amount, 2) }}</span>
        </div>
        @endif
        @if((float)$invoice->tax_amount > 0)
        <div class="row">
          <span>Sales Tax</span>
          <span>${{ number_format($invoice->tax_amount, 2) }}</span>
        </div>
        @endif
        @if((float)$invoice->tags_amount > 0)
        <div class="row">
          <span>Tags / Title</span>
          <span>${{ number_format($invoice->tags_amount, 2) }}</span>
        </div>
        @endif
        @if($invoice->storage_days > 0)
        <div class="row">
          <span>Storage ({{ $invoice->storage_days }} day{{ $invoice->storage_days !== 1 ? 's' : '' }})</span>
          <span>${{ number_format($invoice->storage_fee_amount, 2) }}</span>
        </div>
        @endif
        <div class="row total">
          <span>Total Owed</span>
          <span>${{ number_format($invoice->balance_due, 2) }}</span>
        </div>
      </div>

      <div class="storage-warning">
        ⚠️ <strong>Storage fees are accruing daily.</strong>
        Each additional day increases the amount owed. Pay now to stop the clock.
      </div>

      <div class="cta">
        <a href="{{ config('app.url') }}/my/invoices/{{ $invoice->id }}">Pay Now — ${{ number_format($invoice->balance_due, 2) }}</a>
      </div>

      <p style="color:#64748b;font-size:13px;text-align:center;">
        Questions? Reply to this email or contact our support team immediately.
      </p>
    </div>
    <div class="footer">
      &copy; {{ date('Y') }} Car Auction Platform. All rights reserved.
    </div>
  </div>
</body>
</html>
