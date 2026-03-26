<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Decision Required</title>
    <style>
        body { margin: 0; padding: 0; background: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .wrapper { max-width: 560px; margin: 40px auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .header { background: #ea580c; padding: 32px 32px 24px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -0.3px; }
        .header p { margin: 6px 0 0; font-size: 13px; color: #fed7aa; }
        .badge { display: inline-block; background: #fff; color: #ea580c; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; padding: 4px 12px; border-radius: 99px; margin-bottom: 12px; }
        .body { padding: 32px; }
        .greeting { font-size: 15px; color: #334155; margin: 0 0 20px; line-height: 1.6; }
        .vehicle-card { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
        .vehicle-card .lot { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: #9a3412; margin: 0 0 6px; }
        .vehicle-card .name { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0 0 4px; }
        .vehicle-card .vin { font-size: 12px; color: #64748b; font-family: monospace; margin: 0; }
        .bid-row { display: flex; align-items: baseline; gap: 8px; margin: 20px 0; }
        .bid-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: #64748b; }
        .bid-value { font-size: 28px; font-weight: 900; color: #0f172a; }
        .meta-grid { border-top: 1px solid #fed7aa; padding-top: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .meta-item .label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: #94a3b8; margin: 0 0 3px; }
        .meta-item .value { font-size: 13px; font-weight: 600; color: #334155; margin: 0; }
        .deadline-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 16px; margin-bottom: 24px; }
        .deadline-box p { margin: 0; font-size: 13px; color: #991b1b; font-weight: 600; }
        .deadline-box span { font-size: 15px; font-weight: 800; }
        .note { font-size: 13px; color: #475569; line-height: 1.7; margin: 0; }
        .footer { background: #f8fafc; border-top: 1px solid #f1f5f9; padding: 20px 32px; text-align: center; }
        .footer p { margin: 0; font-size: 12px; color: #94a3b8; line-height: 1.6; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <div class="badge">Action Required</div>
        <h1>Seller Decision Needed</h1>
        <p>A bid below reserve has been submitted for your vehicle.</p>
    </div>

    <div class="body">
        <p class="greeting">
            Hi {{ $lot->vehicle->seller->first_name ?? $lot->vehicle->seller->name }},<br>
            A buyer has placed the highest bid on your vehicle, but it did not meet the reserve price.
            You have <strong>48 hours</strong> to approve or reject this conditional sale.
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

            <div class="bid-row">
                <span class="bid-label">Highest Bid</span>
                <span class="bid-value">${{ number_format($lot->current_bid) }}</span>
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
            </div>
        </div>

        <div class="deadline-box">
            <p>
                Decision deadline:<br>
                <span>{{ $lot->seller_decision_deadline?->format('l, M j, Y \a\t g:i A T') ?? 'Within 48 hours' }}</span>
            </p>
        </div>

        <p class="note">
            Please contact our auction team to approve or reject this sale.
            If no decision is received by the deadline, the sale will be automatically rejected
            and the vehicle returned to available status.
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
