<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; }

        .header { background: #1e293b; color: #fff; padding: 24px 32px; }
        .header h1 { font-size: 22px; font-weight: 700; letter-spacing: 1px; }
        .header .sub { font-size: 11px; color: #94a3b8; margin-top: 4px; }
        .header .meta { text-align: right; }
        .header .meta p { font-size: 11px; margin: 2px 0; }
        .header .meta .date { font-size: 13px; font-weight: 600; color: #f59e0b; }
        .header-inner { display: flex; justify-content: space-between; align-items: flex-start; }

        .section { padding: 20px 32px; }
        .section-title {
            font-size: 10px; text-transform: uppercase; letter-spacing: 1px;
            color: #64748b; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;
        }

        .kpi-grid { display: flex; flex-wrap: wrap; gap: 12px; }
        .kpi-card {
            flex: 1; min-width: 120px; background: #f8fafc;
            border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px;
        }
        .kpi-card .label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .kpi-card .value { font-size: 18px; font-weight: 700; color: #1e293b; margin-top: 4px; }
        .kpi-card .value.amber { color: #d97706; }
        .kpi-card .value.green { color: #059669; }

        table { width: 100%; border-collapse: collapse; }
        table thead tr { background: #f1f5f9; }
        table thead th { padding: 8px 12px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; }
        table tbody tr { border-bottom: 1px solid #f1f5f9; }
        table tbody td { padding: 9px 12px; font-size: 11px; }
        table tbody tr:last-child { border-bottom: none; }
        .text-right { text-align: right; }
        .font-bold { font-weight: 700; }

        .divider { height: 1px; background: #e2e8f0; margin: 0 32px; }

        .footer {
            margin-top: 40px; border-top: 1px solid #e2e8f0;
            padding: 14px 32px; font-size: 10px; color: #94a3b8; text-align: center;
        }
    </style>
</head>
<body>

{{-- Header --}}
<div class="header">
    <div class="header-inner">
        <div>
            <h1>Platform Summary Report</h1>
            <p class="sub">Auto Auction — Admin Dashboard Export</p>
        </div>
        <div class="meta">
            <p class="date">{{ now()->format('F j, Y') }}</p>
            <p>Generated: {{ now()->format('g:i A T') }}</p>
        </div>
    </div>
</div>

{{-- KPI Overview --}}
<div class="section">
    <p class="section-title">Key Performance Indicators</p>
    <div class="kpi-grid">
        <div class="kpi-card">
            <p class="label">Total Users</p>
            <p class="value">{{ number_format($stats['users']) }}</p>
        </div>
        <div class="kpi-card">
            <p class="label">Vehicles</p>
            <p class="value amber">{{ number_format($stats['vehicles']) }}</p>
        </div>
        <div class="kpi-card">
            <p class="label">Total Auctions</p>
            <p class="value">{{ number_format($stats['auctions']) }}</p>
        </div>
        <div class="kpi-card">
            <p class="label">Live Now</p>
            <p class="value">{{ number_format($stats['live_auctions']) }}</p>
        </div>
        <div class="kpi-card">
            <p class="label">Total Revenue</p>
            <p class="value green">${{ number_format($stats['total_revenue'], 2) }}</p>
        </div>
        <div class="kpi-card">
            <p class="label">Pending Revenue</p>
            <p class="value amber">${{ number_format($stats['pending_revenue'], 2) }}</p>
        </div>
        <div class="kpi-card">
            <p class="label">Total Bids</p>
            <p class="value">{{ number_format($stats['bids']) }}</p>
        </div>
        <div class="kpi-card">
            <p class="label">Invoices</p>
            <p class="value">{{ number_format($stats['invoices']) }}</p>
        </div>
    </div>
</div>

<div class="divider"></div>

{{-- Monthly Revenue --}}
<div class="section">
    <p class="section-title">Monthly Revenue — Last 12 Months</p>
    <table>
        <thead>
            <tr>
                <th>Month</th>
                <th class="text-right">Revenue (Paid Invoices)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($revenue as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="text-right">${{ number_format($row['total'], 2) }}</td>
            </tr>
            @endforeach
            <tr>
                <td class="font-bold">Total</td>
                <td class="text-right font-bold">${{ number_format(collect($revenue)->sum('total'), 2) }}</td>
            </tr>
        </tbody>
    </table>
</div>

<div class="divider"></div>

{{-- Auction Breakdown --}}
<div class="section">
    <p class="section-title">Auction Status Breakdown</p>
    <table>
        <thead>
            <tr>
                <th>Status</th>
                <th class="text-right">Count</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($breakdown as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="text-right">{{ number_format($row['count']) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="footer">
    This report is confidential and intended for authorized personnel only. &mdash; Generated by Auto Auction Admin System
</div>

</body>
</html>
