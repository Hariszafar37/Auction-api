<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; color: #1e293b; background: #f8fafc; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 32px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .header { background: #1e293b; padding: 24px 32px; color: #fff; }
        .header h1 { font-size: 20px; margin: 0; }
        .body { padding: 32px; }
        .quote-card { background: #fef9c3; border: 1px solid #fcd34d; border-radius: 6px; padding: 16px; margin: 16px 0; }
        .quote-amount { font-size: 28px; font-weight: 900; color: #1e293b; }
        .btn { display: inline-block; background: #f59e0b; color: #1e293b; font-weight: 700; text-decoration: none; padding: 12px 24px; border-radius: 8px; margin-top: 20px; }
        .footer { padding: 16px 32px; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Transport Quote Received</h1>
    </div>
    <div class="body">
        <p>Hi {{ $transportRequest->buyer?->name }},</p>
        <p>We have received a transport quote for your vehicle. Please review the details below.</p>

        @php
            $vehicle = $transportRequest->lot?->vehicle;
        @endphp

        @if($vehicle)
        <p><strong>Vehicle:</strong> {{ $vehicle->year }} {{ $vehicle->make }} {{ $vehicle->model }}{{ $vehicle->trim ? ' ' . $vehicle->trim : '' }}</p>
        <p><strong>VIN:</strong> {{ $vehicle->vin }}</p>
        @endif

        <div class="quote-card">
            <p style="margin-bottom: 6px; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #78350f;">Transport Quote</p>
            <div class="quote-amount">${{ number_format((float) $transportRequest->quote_amount, 2) }}</div>
            <p style="margin-top: 6px;"><strong>From:</strong> {{ $transportRequest->pickup_location }}</p>
            <p><strong>To:</strong> {{ $transportRequest->delivery_address }}</p>
            @if($transportRequest->admin_notes)
            <p style="margin-top: 8px; font-size: 12px; color: #92400e;"><strong>Note:</strong> {{ $transportRequest->admin_notes }}</p>
            @endif
        </div>

        <p>Log in to your account to accept or get more information about this transport arrangement.</p>

        <a href="{{ config('app.url') }}/my/purchases/{{ $transportRequest->lot_id }}" class="btn">View Purchase Details</a>
    </div>
    <div class="footer">
        &copy; {{ now()->year }} Auto Auction Platform. This is an automated notification.
    </div>
</div>
</body>
</html>
