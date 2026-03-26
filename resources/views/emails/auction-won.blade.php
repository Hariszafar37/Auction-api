<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You Won the Auction!</title>
    <style>
        body { margin: 0; padding: 0; background: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .wrapper { max-width: 560px; margin: 40px auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .header { background: #1e293b; padding: 32px 32px 24px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -0.3px; }
        .header p { margin: 6px 0 0; font-size: 13px; color: #94a3b8; }
        .badge { display: inline-block; background: #f59e0b; color: #1e293b; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; padding: 4px 12px; border-radius: 99px; margin-bottom: 12px; }
        .body { padding: 32px; }
        .greeting { font-size: 15px; color: #334155; margin: 0 0 20px; }
        .vehicle-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
        .vehicle-card .lot { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: #94a3b8; margin: 0 0 6px; }
        .vehicle-card .name { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0 0 4px; }
        .vehicle-card .vin { font-size: 12px; color: #64748b; font-family: monospace; margin: 0; }
        .price-row { display: flex; align-items: baseline; gap: 8px; margin: 20px 0; }
        .price-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: #64748b; }
        .price-value { font-size: 28px; font-weight: 900; color: #0f172a; }
        .meta-grid { border-top: 1px solid #f1f5f9; padding-top: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .meta-item .label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: #94a3b8; margin: 0 0 3px; }
        .meta-item .value { font-size: 13px; font-weight: 600; color: #334155; margin: 0; }
        .footer { background: #f8fafc; border-top: 1px solid #f1f5f9; padding: 20px 32px; text-align: center; }
        .footer p { margin: 0; font-size: 12px; color: #94a3b8; line-height: 1.6; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <div class="badge">Auction Winner</div>
        <h1>Congratulations!</h1>
        <p>You are the winning bidder.</p>
    </div>

    <div class="body">
        <p class="greeting">
            Hi {{ $lot->buyer->first_name ?? $lot->buyer->name }},<br>
            You've won the auction for the following vehicle:
        </p>

        <div class="vehicle-card">
            <p class="lot">Lot {{ $lot->lot_number }}</p>
            <p class="name">
                {{ $lot->vehicle->year }}
                {{ $lot->vehicle->make }}
                {{ $lot->vehicle->model }}
                @if($lot->vehicle->trim) {{ $lot->vehicle->trim }} @endif
            </p>
            <p class="vin">VIN: {{ $lot->vehicle->vin }}</p>

            <div class="price-row">
                <span class="price-label">Winning Bid</span>
                <span class="price-value">${{ number_format($lot->sold_price) }}</span>
            </div>

            <div class="meta-grid">
                <div class="meta-item">
                    <p class="label">Auction</p>
                    <p class="value">{{ $lot->auction->title }}</p>
                </div>
                <div class="meta-item">
                    <p class="label">Location</p>
                    <p class="value">{{ $lot->auction->location }}</p>
                </div>
                <div class="meta-item">
                    <p class="label">Auction Date</p>
                    <p class="value">{{ $lot->auction->starts_at->format('M j, Y') }}</p>
                </div>
                <div class="meta-item">
                    <p class="label">Lot Closed</p>
                    <p class="value">{{ $lot->closed_at?->format('M j, Y g:i A') ?? '—' }}</p>
                </div>
            </div>
        </div>

        <p style="font-size:13px;color:#475569;line-height:1.7;margin:0;">
            Our team will be in touch shortly with next steps for payment and vehicle pickup.
            Please have your buyer information and payment method ready.
        </p>
    </div>

    <div class="footer">
        <p>
            This email was sent by <strong>Car Auction Platform</strong>.<br>
            If you have questions, please contact us.
        </p>
    </div>
</div>
</body>
</html>
