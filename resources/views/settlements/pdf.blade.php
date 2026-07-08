<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; }
        .header { background: #1e293b; color: #fff; padding: 24px 32px; }
        .header h1 { font-size: 20px; font-weight: 700; letter-spacing: 1px; }
        .header .meta { margin-top: 6px; font-size: 11px; color: #cbd5e1; }
        .header .number { font-size: 15px; font-weight: 600; color: #f59e0b; }
        .section { padding: 18px 32px; }
        .section-title { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; margin-bottom: 8px; }
        .grid-2 { width: 100%; }
        .grid-2 td { vertical-align: top; width: 50%; padding-right: 16px; }
        .info p { margin: 3px 0; font-size: 12px; }
        .info strong { color: #1e293b; }
        .vehicle-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; margin: 4px 0 12px; }
        .vehicle-box h3 { font-size: 14px; font-weight: 700; margin-bottom: 4px; }
        .totals-table { width: 340px; margin-left: auto; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
        .totals-table tr td { padding: 8px 16px; font-size: 12px; }
        .totals-table tr:nth-child(odd) { background: #f8fafc; }
        .totals-table td.amount { text-align: right; }
        .totals-table .deduct td.amount { color: #b91c1c; }
        .totals-table .net-row td { font-weight: 700; font-size: 14px; background: #1e293b; color: #fff; }
        .totals-table .net-neg td { background: #b91c1c; }
        .status-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; background: #e2e8f0; color: #334155; }
        table.workflow { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.workflow td { padding: 6px 0; font-size: 12px; }
        table.workflow td.label { color: #64748b; width: 45%; }
        .footer { margin-top: 24px; border-top: 1px solid #e2e8f0; padding: 16px 32px; font-size: 10px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>

@php($s = $settlement)

<div class="header">
    <h1>COLONIAL AUCTION SERVICES</h1>
    <p class="meta">Seller Settlement Statement</p>
    <p class="number" style="margin-top:8px;">{{ $s->settlement_number }}</p>
    <p class="meta">
        Date: {{ $s->created_at->format('M d, Y') }} &nbsp;|&nbsp;
        Status: <span class="status-badge">{{ $s->status->label() }}</span>
    </p>
</div>

<div class="section">
    <table class="grid-2"><tr>
        <td>
            <div class="info">
                <p class="section-title">Seller</p>
                <p><strong>{{ $s->seller?->name ?? '—' }}</strong></p>
                <p>{{ $s->seller?->email ?? '' }}</p>
            </div>
        </td>
        <td>
            <div class="info">
                <p class="section-title">Auction</p>
                <p><strong>{{ $s->auction?->title ?? '—' }}</strong></p>
                <p>{{ $s->auction?->location ?? '' }}</p>
                @if($s->auction?->ends_at)<p>Auction date: {{ $s->auction->ends_at->format('M d, Y') }}</p>@endif
                <p>Lot #{{ $s->lot?->lot_number ?? '—' }}</p>
            </div>
        </td>
    </tr></table>
</div>

<div class="section" style="padding-top:0;">
    <p class="section-title">Vehicle</p>
    <div class="vehicle-box">
        <h3>{{ $s->vehicle ? trim("{$s->vehicle->year} {$s->vehicle->make} {$s->vehicle->model} {$s->vehicle->trim}") : '—' }}</h3>
        <p>VIN: {{ $s->vehicle?->vin ?? '—' }} &nbsp;|&nbsp; Outcome: {{ ucfirst(str_replace('_',' ', (string) ($s->outcome ?? '—'))) }}</p>
    </div>

    <table class="totals-table">
        <tr>
            <td>Sale Price</td>
            <td class="amount">{{ $s->sale_price !== null ? '$'.number_format($s->sale_price, 2) : '—' }}</td>
        </tr>
        <tr class="deduct">
            <td>Registration Fee</td>
            <td class="amount">− ${{ number_format((float) $s->registration_fee, 2) }}</td>
        </tr>
        @if($s->isSold())
        <tr class="deduct">
            <td>Seller Commission</td>
            <td class="amount">− ${{ number_format((float) $s->commission_amount, 2) }}</td>
        </tr>
        @else
        <tr class="deduct">
            <td>No Sale Fee</td>
            <td class="amount">− ${{ number_format((float) $s->no_sale_fee, 2) }}</td>
        </tr>
        @endif
        @foreach($s->adjustments as $adj)
        <tr class="{{ $adj->amount < 0 ? 'deduct' : '' }}">
            <td>Adjustment — {{ $adj->reason }}</td>
            <td class="amount">{{ $adj->amount < 0 ? '−' : '+' }} ${{ number_format(abs((float) $adj->amount), 2) }}</td>
        </tr>
        @endforeach
        <tr class="net-row {{ (float) $s->net_proceeds < 0 ? 'net-neg' : '' }}">
            <td>{{ (float) $s->net_proceeds < 0 ? 'Fees Owed By Seller' : 'Net Proceeds' }}</td>
            <td class="amount">${{ number_format(abs((float) $s->net_proceeds), 2) }}</td>
        </tr>
    </table>
</div>

<div class="section" style="padding-top:0;">
    <p class="section-title">Release &amp; Payment</p>
    <table class="workflow">
        <tr><td class="label">Expected Release Date</td><td>{{ $s->release_date?->format('M d, Y') ?? '—' }}</td></tr>
        <tr><td class="label">Released</td><td>{{ $s->released_at?->format('M d, Y') ?? '—' }}</td></tr>
        <tr><td class="label">Check Number</td><td>{{ $s->check_number ?? '—' }}</td></tr>
        <tr><td class="label">Check Issued</td><td>{{ $s->check_issued_at?->format('M d, Y') ?? '—' }}</td></tr>
        <tr><td class="label">Paid</td><td>{{ $s->paid_at?->format('M d, Y') ?? '—' }}</td></tr>
        @if($s->collected_at)
        <tr><td class="label">Fees Collected</td><td>{{ $s->collected_at->format('M d, Y') }} @if($s->collection_method)({{ ucfirst(str_replace('_',' ', $s->collection_method)) }})@endif</td></tr>
        @endif
    </table>
</div>

<div class="footer">
    This statement is generated by Colonial Auction Services. Amounts are shown in USD.
    For questions about your settlement, contact the auction office.
</div>

</body>
</html>
