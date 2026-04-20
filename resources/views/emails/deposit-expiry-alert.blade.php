<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Deposit Hold Expiring — {{ $invoice->invoice_number }}</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f4f4f5; margin: 0; padding: 0; }
    .wrapper { max-width: 580px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .header { background: #7f1d1d; padding: 28px 36px; }
    .header h1 { color: #fca5a5; margin: 0; font-size: 20px; }
    .body { padding: 28px 36px; }
    .alert { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 16px 20px; margin-bottom: 20px; }
    .alert p { color: #991b1b; margin: 0; font-size: 14px; }
    .detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
    .detail-row:last-child { border-bottom: none; }
    .label { color: #64748b; }
    .value { font-weight: 600; color: #0f172a; }
    .footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 16px 36px; font-size: 12px; color: #94a3b8; text-align: center; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>⚠️ Admin Alert — Deposit Hold Expiring</h1>
    </div>
    <div class="body">
      <div class="alert">
        <p>The Stripe deposit hold for the invoice below will expire within 24 hours (Stripe's 7-day limit). Take action immediately to avoid losing the hold.</p>
      </div>

      <div class="detail-row"><span class="label">Invoice</span><span class="value">{{ $invoice->invoice_number }}</span></div>
      <div class="detail-row"><span class="label">Buyer</span><span class="value">{{ $invoice->buyer->name ?? 'N/A' }}</span></div>
      <div class="detail-row"><span class="label">Buyer Email</span><span class="value">{{ $invoice->buyer->email ?? 'N/A' }}</span></div>
      <div class="detail-row"><span class="label">Lot #</span><span class="value">{{ $invoice->lot->lot_number ?? 'N/A' }}</span></div>
      <div class="detail-row"><span class="label">Deposit Amount</span><span class="value">${{ number_format($invoice->deposit_amount, 2) }}</span></div>
      <div class="detail-row"><span class="label">Invoice Created</span><span class="value">{{ $invoice->created_at->format('F j, Y') }}</span></div>
      <div class="detail-row"><span class="label">Stripe PI</span><span class="value">{{ $invoice->stripe_deposit_intent_id }}</span></div>
    </div>
    <div class="footer">
      Car Auction Platform — Admin Notification &copy; {{ date('Y') }}
    </div>
  </div>
</body>
</html>
