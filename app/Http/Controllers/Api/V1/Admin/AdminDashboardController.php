<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    /**
     * GET /api/v1/admin/dashboard/stats
     * Aggregate KPIs for the admin dashboard.
     */
    public function stats(): JsonResponse
    {
        return $this->success([
            'users'           => User::count(),
            'vehicles'        => Vehicle::count(),
            'auctions'        => Auction::count(),
            'live_auctions'   => Auction::where('status', 'live')->count(),
            'invoices'        => Invoice::count(),
            'total_revenue'   => (float) Invoice::where('status', 'paid')->sum('total_amount'),
            'pending_revenue' => (float) Invoice::whereIn('status', ['pending', 'partial', 'overdue'])->sum('balance_due'),
            'bids'            => Bid::count(),
        ]);
    }

    /**
     * GET /api/v1/admin/dashboard/revenue
     * Monthly paid invoice totals for the last 12 months.
     */
    public function revenue(): JsonResponse
    {
        $rows = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as month, SUM(total_amount) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $key      = now()->subMonths($i)->format('Y-m');
            $months[] = [
                'month' => $key,
                'label' => now()->subMonths($i)->format('M Y'),
                'total' => (float) ($rows[$key]?->total ?? 0),
            ];
        }

        return $this->success($months);
    }

    /**
     * GET /api/v1/admin/dashboard/auction-breakdown
     * Auction counts grouped by status.
     */
    public function auctionBreakdown(): JsonResponse
    {
        $breakdown = Auction::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status->value,
                'label'  => ucfirst($row->status->value),
                'count'  => (int) $row->count,
            ]);

        return $this->success($breakdown->values());
    }
}
