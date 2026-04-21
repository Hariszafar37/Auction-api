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
        .vehicle-card { background: #f1f5f9; border-radius: 6px; padding: 16px; margin: 16px 0; }
        .vehicle-card h2 { font-size: 16px; margin: 0 0 8px; }
        .badge { display: inline-block; background: #10b981; color: #fff; font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 20px; }
        .btn { display: inline-block; background: #f59e0b; color: #1e293b; font-weight: 700; text-decoration: none; padding: 12px 24px; border-radius: 8px; margin-top: 20px; }
        .footer { padding: 16px 32px; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Your Vehicle Is Ready for Pickup</h1>
    </div>
    <div class="body">
        <p>Hi {{ $purchase->buyer?->name }},</p>
        <p>Great news — your payment has been confirmed and your vehicle is now ready for pickup.</p>

        @php
            $lot     = $purchase->lot;
            $vehicle = $lot?->vehicle;
            $auction = $lot?->auction;
        @endphp

        <div class="vehicle-card">
            <span class="badge">READY FOR PICKUP</span>
            <h2 style="margin-top: 10px;">{{ $vehicle?->year }} {{ $vehicle?->make }} {{ $vehicle?->model }}{{ $vehicle?->trim ? ' ' . $vehicle->trim : '' }}</h2>
            <p>VIN: <strong>{{ $vehicle?->vin }}</strong></p>
            <p>Lot #{{ $lot?->lot_number }}</p>
        </div>

        @if($auction)
        <p><strong>Pickup Location:</strong> {{ $auction->location }}</p>
        @endif

        @if($purchase->pickup_notes)
        <p><strong>Pickup Notes from the Auction House:</strong></p>
        <p>{{ $purchase->pickup_notes }}</p>
        @endif

        <p>Download your gate pass from your purchase detail page and bring it to the yard for vehicle release.</p>

        <a href="{{ config('app.url') }}/my/purchases/{{ $purchase->lot_id }}" class="btn">View Purchase &amp; Download Gate Pass</a>
    </div>
    <div class="footer">
        &copy; {{ now()->year }} Auto Auction Platform. This is an automated notification.
    </div>
</div>
</body>
</html>
