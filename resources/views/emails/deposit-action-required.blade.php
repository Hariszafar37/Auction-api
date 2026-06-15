<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Action needed — deposit for Invoice {{ $invoice->invoice_number }}</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f4f4f5; margin: 0; padding: 0; }
    .wrapper { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .header { background: #1e293b; padding: 32px 40px; }
    .header h1 { color: #f59e0b; margin: 0; font-size: 22px; }
    .header p { color: #94a3b8; margin: 4px 0 0; font-size: 14px; }
    .body { padding: 32px 40px; }
    .greeting { font-size: 16px; color: #334155; margin-bottom: 20px; }
    .alert { background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 16px 20px; margin-bottom: 24px; color: #b91c1c; font-size: 14px; }
    .invoice-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 20px 24px; margin-bottom: 24px; }
    .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; color: #475569; }
    .row:last-child { border-bottom: none; }
    .cta { text-align: center; margin: 28px 0; }
    .cta a { background: #f59e0b; color: #fff; text-decoration: none; padding: 12px 32px; border-radius: 6px; font-weight: bold; font-size: 15px; display: inline-block; }
    .footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 40px; font-size: 12px; color: #94a3b8; text-align: center; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <img src="{{ asset('images/colonial-logo.png') }}" alt="Colonial Auction Services, Inc." width="170" style="display:inline-block; background:#fff; padding:6px 12px; border-radius:8px; margin-bottom:10px;">
      <p>Deposit — Action Required</p>
    </div>
    <div class="body">
      <p class="greeting">Hello {{ $invoice->buyer->name ?? 'Valued Customer' }},</p>

      <div class="alert">
        <strong>{{ $headline }}.</strong>
        @if($reason === 'requires_action')
          Your card issuer requires additional confirmation before we can take your deposit.
        @elseif($reason === 'no_payment_method')
          We don't have a usable card on file to take your deposit.
        @else
          We were unable to charge the deposit to your card on file.
        @endif
      </div>

      <p style="color:#475569;font-size:14px;">
        Congratulations on your winning bid
        @if($invoice->vehicle) on the {{ $invoice->vehicle->year }} {{ $invoice->vehicle->make }} {{ $invoice->vehicle->model }}@endif.
        To secure your purchase, please update or confirm your payment method so we can
        collect the ${{ number_format((float) $invoice->deposit_amount, 2) }} deposit. This
        amount is credited toward your invoice total — it is not an extra charge.
      </p>

      <div class="invoice-box">
        <div class="row">
          <span>Invoice</span>
          <span>{{ $invoice->invoice_number }}</span>
        </div>
        <div class="row">
          <span>Deposit due</span>
          <span>${{ number_format((float) $invoice->deposit_amount, 2) }}</span>
        </div>
        @if($invoice->due_at)
        <div class="row">
          <span>Pay by</span>
          <span>{{ $invoice->due_at->format('M j, Y') }}</span>
        </div>
        @endif
      </div>

      <div class="cta">
        <a href="{{ config('app.url') }}/payment-information?returnTo=/my/invoices/{{ $invoice->id }}">Update payment method</a>
      </div>

      <p style="color:#94a3b8;font-size:13px;">
        If the deposit isn't completed by the due date, your purchase may be cancelled and
        the deposit forfeited per the auction terms.
      </p>
    </div>
    <div class="footer">
      Colonial Auction Services, Inc. · This is an automated message, please do not reply.
    </div>
  </div>
</body>
</html>
