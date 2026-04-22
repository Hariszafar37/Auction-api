<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; }

        .header { background: #1e293b; color: #fff; padding: 24px 32px; }
        .header h1 { font-size: 22px; font-weight: 700; letter-spacing: 1px; }
        .header p { font-size: 11px; color: #94a3b8; margin-top: 4px; }

        .paid-stamp {
            display: inline-block;
            border: 4px solid #10b981;
            color: #10b981;
            font-size: 28px;
            font-weight: 900;
            letter-spacing: 4px;
            padding: 6px 18px;
            transform: rotate(-8deg);
            margin: 20px auto;
            text-align: center;
        }

        .stamp-wrapper { text-align: center; padding: 12px 32px; }

        .section { padding: 16px 32px; }
        .section-title { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; margin-bottom: 6px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }

        .grid-2 { display: flex; gap: 32px; }
        .grid-2 > div { flex: 1; }

        .info-row { display: flex; margin: 4px 0; font-size: 11px; }
        .info-label { color: #64748b; width: 140px; flex-shrink: 0; }
        .info-value { color: #1e293b; font-weight: 600; }

        .vehicle-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; }
        .vehicle-box h3 { font-size: 16px; font-weight: 700; margin-bottom: 6px; color: #1e293b; }

        .lot-badge { display: inline-block; background: #f59e0b; color: #1e293b; font-weight: 700; font-size: 11px; padding: 2px 10px; border-radius: 20px; margin-bottom: 8px; }

        .verify-box { margin: 20px 32px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 16px; }
        .verify-box p { font-size: 10px; color: #475569; margin-bottom: 4px; }
        .verify-url { font-size: 10px; color: #1e40af; word-break: break-all; }

        .footer { border-top: 1px solid #e2e8f0; padding: 16px 32px; font-size: 10px; color: #94a3b8; text-align: center; margin-top: 24px; }
    </style>
</head>
<body>

@php
    $lot       = $purchase->lot;
    $vehicle   = $lot?->vehicle;
    $auction   = $lot?->auction;
    $buyer     = $purchase->buyer;
    $invoice   = $purchase->invoice;
    $verifyUrl = url('/api/v1/verify/gate-pass/' . $purchase->gate_pass_token);
    $qrSvg = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(120)->generate($verifyUrl);
@endphp

<div class="header">
    <h1>AUTO AUCTION</h1>
    <p>GATE PASS — VEHICLE RELEASE DOCUMENT</p>
</div>

<div class="stamp-wrapper">
    @if($invoice?->isPaid())
    <div class="paid-stamp">PAID IN FULL</div>
    @endif
</div>

<div class="section">
    <div class="lot-badge">Lot #{{ $lot?->lot_number }}</div>

    <div class="grid-2" style="margin-top: 12px;">
        <div>
            <div class="section-title">Buyer Information</div>
            <div class="info-row">
                <span class="info-label">Buyer Name</span>
                <span class="info-value">{{ $buyer?->name }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Buyer Email</span>
                <span class="info-value">{{ $buyer?->email }}</span>
            </div>
        </div>
        <div>
            <div class="section-title">Auction Information</div>
            <div class="info-row">
                <span class="info-label">Auction</span>
                <span class="info-value">{{ $auction?->title }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Location</span>
                <span class="info-value">{{ $auction?->location }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Sale Date</span>
                <span class="info-value">{{ $invoice?->created_at?->format('M d, Y') }}</span>
            </div>
        </div>
    </div>
</div>

<div class="section">
    <div class="section-title">Vehicle Details</div>
    <div class="vehicle-box">
        <h3>{{ $vehicle?->year }} {{ $vehicle?->make }} {{ $vehicle?->model }}{{ $vehicle?->trim ? ' ' . $vehicle->trim : '' }}</h3>
        <div class="grid-2">
            <div>
                <div class="info-row"><span class="info-label">VIN</span><span class="info-value">{{ $vehicle?->vin }}</span></div>
                <div class="info-row"><span class="info-label">Mileage</span><span class="info-value">{{ $vehicle?->mileage ? number_format($vehicle->mileage) . ' mi' : '—' }}</span></div>
                <div class="info-row"><span class="info-label">Condition</span><span class="info-value">{{ ucfirst($vehicle?->condition_light ?? '—') }}</span></div>
            </div>
            <div>
                <div class="info-row"><span class="info-label">Sale Price</span><span class="info-value">${{ number_format($lot?->sold_price ?? 0) }}</span></div>
                <div class="info-row"><span class="info-label">Invoice #</span><span class="info-value">{{ $invoice?->invoice_number }}</span></div>
                <div class="info-row"><span class="info-label">Paid At</span><span class="info-value">{{ $invoice?->paid_at?->format('M d, Y') ?? '—' }}</span></div>
            </div>
        </div>
    </div>
</div>

<div class="verify-box">
    <div style="display:flex; gap:16px; align-items:flex-start;">
        <div style="width:100px;height:100px;flex-shrink:0;">
            {!! $qrSvg !!}
        </div>
        <div>
            <p>VERIFICATION — Yard staff: scan the QR code or visit the URL below to verify this gate pass in real time.</p>
            <p class="verify-url" style="margin-top:6px;">{{ $verifyUrl }}</p>
            <p style="margin-top: 6px; font-size:9px; color:#94a3b8;">Gate Pass Token: {{ $purchase->gate_pass_token }}</p>
        </div>
    </div>
</div>

<div class="footer">
    This gate pass authorizes the release of the above vehicle to the named buyer only. Valid only when payment is confirmed in our system. &copy; {{ now()->year }} Auto Auction Platform.
</div>

</body>
</html>
