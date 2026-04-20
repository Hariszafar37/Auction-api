<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payment Confirmed — Invoice {{ $invoice->invoice_number }}</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f4f4f5; margin: 0; padding: 0; }
    .wrapper { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .header { background: #064e3b; padding: 32px 40px; }
    .header h1 { color: #34d399; margin: 0; font-size: 22px; }
    .header p { color: #a7f3d0; margin: 4px 0 0; font-size: 14px; }
    .body { padding: 32px 40px; }
    .greeting { font-size: 16px; color: #334155; margin-bottom: 20px; }
    .success-badge { background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 6px; padding: 16px 20px; display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
    .success-badge .icon { font-size: 28px; }
    .success-badge .text strong { color: #065f46; font-size: 15px; display: block; }
    .success-badge .text span { color: #047857; font-size: 13px; }
    .invoice-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 20px 24px; margin-bottom: 24px; }
    .invoice-box h2 { margin: 0 0 12px; font-size: 16px; color: #0f172a; }
    .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; color: #475569; }
    .row:last-child { border-bottom: none; }
    .row.total { font-weight: bold; color: #0f172a; }
    .row.paid { color: #059669; font-weight: bold; }
    .row.balance { color: #d97706; font-weight: bold; }
    .attachment-note { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; padding: 12px 16px; font-size: 13px; color: #92400e; margin-bottom: 20px; }
    .footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 40px; font-size: 12px; color: #94a3b8; text-align: center; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>Car Auction Platform</h1>
      <p>Payment Confirmation</p>
    </div>
    <div class="body">
      <p class="greeting">Hello {{ $invoice->buyer->name ?? 'Valued Customer' }},</p>

      <div class="success-badge">
        <div class="icon">✅</div>
        <div class="text">
          <strong>Payment Received</strong>
          <span>${{ number_format($payment->amount, 2) }} via {{ $payment->method_label ?? ucwords(str_replace('_', ' ', $payment->method->value)) }}</span>
        </div>
      </div>

      <div class="invoice-box">
        <h2>Invoice {{ $invoice->invoice_number }}</h2>
        @if($invoice->vehicle)
        <div class="row">
          <span>Vehicle</span>
          <span>{{ $invoice->vehicle->year }} {{ $invoice->vehicle->make }} {{ $invoice->vehicle->model }}</span>
        </div>
        @endif
        <div class="row total">
          <span>Invoice Total</span>
          <span>${{ number_format($invoice->total_amount, 2) }}</span>
        </div>
        <div class="row paid">
          <span>Total Paid</span>
          <span>${{ number_format($invoice->amount_paid, 2) }}</span>
        </div>
        @if((float)$invoice->balance_due > 0)
        <div class="row balance">
          <span>Remaining Balance</span>
          <span>${{ number_format($invoice->balance_due, 2) }}</span>
        </div>
        @else
        <div class="row paid">
          <span>Status</span>
          <span>Paid in Full ✓</span>
        </div>
        @endif
      </div>

      <div class="attachment-note">
        📎 A copy of your invoice PDF is attached to this email for your records.
      </div>

      <p style="color:#64748b;font-size:13px;text-align:center;">
        Thank you for your payment. Questions? Contact our support team.
      </p>
    </div>
    <div class="footer">
      &copy; {{ date('Y') }} Car Auction Platform. All rights reserved.
    </div>
  </div>
</body>
</html>
