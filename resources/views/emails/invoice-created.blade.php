<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Invoice {{ $invoice->invoice_number }} — Payment Due</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f4f4f5; margin: 0; padding: 0; }
    .wrapper { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .header { background: #1e293b; padding: 32px 40px; }
    .header h1 { color: #f59e0b; margin: 0; font-size: 22px; }
    .header p { color: #94a3b8; margin: 4px 0 0; font-size: 14px; }
    .body { padding: 32px 40px; }
    .greeting { font-size: 16px; color: #334155; margin-bottom: 20px; }
    .invoice-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 20px 24px; margin-bottom: 24px; }
    .invoice-box h2 { margin: 0 0 12px; font-size: 18px; color: #0f172a; }
    .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; color: #475569; }
    .row:last-child { border-bottom: none; }
    .row.total { font-weight: bold; color: #0f172a; font-size: 16px; }
    .row.due { color: #d97706; font-weight: bold; }
    .cta { text-align: center; margin: 28px 0; }
    .cta a { background: #f59e0b; color: #fff; text-decoration: none; padding: 12px 32px; border-radius: 6px; font-weight: bold; font-size: 15px; display: inline-block; }
    .footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 40px; font-size: 12px; color: #94a3b8; text-align: center; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>Car Auction Platform</h1>
      <p>Invoice Ready — Payment Due</p>
    </div>
    <div class="body">
      <p class="greeting">Hello {{ $invoice->buyer->name ?? 'Valued Customer' }},</p>
      <p style="color:#475569;font-size:14px;">
        Your auction purchase has been confirmed and an invoice has been generated.
        Please review the details below and complete payment by the due date.
      </p>

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
        <div class="row total">
          <span>Total Due</span>
          <span>${{ number_format($invoice->total_amount, 2) }}</span>
        </div>
        @if($invoice->due_at)
        <div class="row due">
          <span>Due By</span>
          <span>{{ $invoice->due_at->format('F j, Y') }}</span>
        </div>
        @endif
      </div>

      <div class="cta">
        <a href="{{ config('app.url') }}/my/invoices/{{ $invoice->id }}">View &amp; Pay Invoice</a>
      </div>

      <p style="color:#64748b;font-size:13px;text-align:center;">
        Questions? Reply to this email or contact our support team.
      </p>
    </div>
    <div class="footer">
      &copy; {{ date('Y') }} Car Auction Platform. All rights reserved.
    </div>
  </div>
</body>
</html>
