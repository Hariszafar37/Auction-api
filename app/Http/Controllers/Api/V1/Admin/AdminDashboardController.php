<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\BusinessProfile;
use App\Models\DealerProfile;
use App\Models\Dispute;
use App\Models\GovProfile;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PowerOfAttorney;
use App\Models\SellerProfile;
use App\Models\SellerSettlement;
use App\Models\User;
use App\Models\Vehicle;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

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
            'pending'         => $this->pendingActionCounts(),
        ]);
    }

    /**
     * Action-required counts for the admin dashboard's "Needs Attention" section.
     *
     * Each value is a live query against the authoritative table, keyed to the exact
     * status an admin must act on. `user_applications` is the overall onboarding queue
     * (pending_activation) and intentionally overlaps the dealer/business/government
     * cards, so it is NOT summed into a grand total here.
     *
     * @return array<string,int>
     */
    private function pendingActionCounts(): array
    {
        return [
            // Accounts awaiting admin activation (overall onboarding queue).
            'user_applications'       => User::where('status', 'pending_activation')->count(),
            // Per-type account applications (profile approval_status = pending).
            'dealer_applications'     => DealerProfile::where('approval_status', 'pending')->count(),
            'seller_applications'     => SellerProfile::where('approval_status', 'pending')->count(),
            'business_applications'   => BusinessProfile::where('approval_status', 'pending')->count(),
            'government_applications' => GovProfile::where('approval_status', 'pending')->count(),
            // Power of Attorney documents awaiting review.
            'poa_approvals'           => PowerOfAttorney::where('status', 'pending')->count(),
            // Offline payments (cash/check/wire) awaiting admin verification.
            'payments'                => InvoicePayment::where('status', 'pending_verification')->count(),
            // Vehicles in the active pipeline whose physical title has not yet been received.
            'titles'                  => Vehicle::where('has_title', true)
                ->where('title_received', false)
                ->whereIn('status', ['available', 'in_auction', 'sold'])
                ->count(),
            // Buyer disputes needing a decision.
            'disputes'                => Dispute::whereIn('status', ['open', 'under_review'])->count(),
            // Seller settlements whose release date has passed and a check must be issued.
            'settlements'             => SellerSettlement::where('status', 'ready_for_release')->count(),
        ];
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

    /**
     * GET /api/v1/admin/dashboard/report/pdf
     * Generates and streams a platform summary PDF report.
     */
    public function reportPdf(): Response
    {
        $stats = [
            'users'           => User::count(),
            'vehicles'        => Vehicle::count(),
            'auctions'        => Auction::count(),
            'live_auctions'   => Auction::where('status', 'live')->count(),
            'invoices'        => Invoice::count(),
            'total_revenue'   => (float) Invoice::where('status', 'paid')->sum('total_amount'),
            'pending_revenue' => (float) Invoice::whereIn('status', ['pending', 'partial', 'overdue'])->sum('balance_due'),
            'bids'            => Bid::count(),
        ];

        $revenueRows = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as month, SUM(total_amount) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $revenue = [];
        for ($i = 11; $i >= 0; $i--) {
            $key       = now()->subMonths($i)->format('Y-m');
            $revenue[] = [
                'month' => $key,
                'label' => now()->subMonths($i)->format('M Y'),
                'total' => (float) ($revenueRows[$key]?->total ?? 0),
            ];
        }

        $breakdown = Auction::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status->value,
                'label'  => ucfirst($row->status->value),
                'count'  => (int) $row->count,
            ])
            ->values()
            ->toArray();

        $pdf = Pdf::loadView('reports.admin-summary', compact('stats', 'revenue', 'breakdown'))
            ->setPaper('a4', 'portrait');

        $filename = 'platform-report-' . now()->format('Y-m-d') . '.pdf';

        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
