<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\IssueSettlementCheckRequest;
use App\Http\Requests\Payment\MarkSettlementCollectedRequest;
use App\Http\Requests\Payment\MarkSettlementPaidRequest;
use App\Http\Requests\Payment\SettlementAdjustmentRequest;
use App\Http\Resources\Payment\SellerSettlementResource;
use App\Models\SellerSettlement;
use App\Services\Payment\SellerSettlementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSellerSettlementController extends Controller
{
    public function __construct(
        private readonly SellerSettlementService $settlements,
    ) {}

    /**
     * GET /admin/settlements
     */
    public function index(Request $request): JsonResponse
    {
        $settlements = $this->filteredQuery($request)
            ->with(['lot', 'auction', 'vehicle', 'seller'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->success(
            SellerSettlementResource::collection($settlements),
            meta: [
                'current_page' => $settlements->currentPage(),
                'last_page'    => $settlements->lastPage(),
                'per_page'     => $settlements->perPage(),
                'total'        => $settlements->total(),
            ]
        );
    }

    /**
     * GET /admin/settlements/{settlement}
     */
    public function show(SellerSettlement $settlement): JsonResponse
    {
        $settlement->load(['lot', 'auction', 'vehicle', 'seller', 'adjustments.author']);

        return $this->success(new SellerSettlementResource($settlement));
    }

    /**
     * GET /admin/settlements/summary — aggregate KPIs over the current filter.
     */
    public function summary(Request $request): JsonResponse
    {
        return $this->success($this->settlements->summarize($this->filteredQuery($request)));
    }

    /**
     * POST /admin/settlements/{settlement}/ready
     */
    public function markReady(SellerSettlement $settlement): JsonResponse
    {
        $settlement = $this->settlements->markReadyForRelease($settlement);
        $settlement->load(['lot', 'auction', 'vehicle', 'seller']);

        return $this->success(new SellerSettlementResource($settlement));
    }

    /**
     * POST /admin/settlements/{settlement}/issue-check
     */
    public function issueCheck(IssueSettlementCheckRequest $request, SellerSettlement $settlement): JsonResponse
    {
        $settlement = $this->settlements->issueCheck($settlement, $request->input('check_number'));
        $settlement->load(['lot', 'auction', 'vehicle', 'seller']);

        return $this->success(new SellerSettlementResource($settlement));
    }

    /**
     * POST /admin/settlements/{settlement}/mark-paid
     */
    public function markPaid(MarkSettlementPaidRequest $request, SellerSettlement $settlement): JsonResponse
    {
        $settlement = $this->settlements->markPaid($settlement, $request->input('paid_at'));
        $settlement->load(['lot', 'auction', 'vehicle', 'seller']);

        return $this->success(new SellerSettlementResource($settlement));
    }

    /**
     * POST /admin/settlements/{settlement}/mark-collected
     * Interim collection of no-sale fees — records how / by whom.
     */
    public function markCollected(MarkSettlementCollectedRequest $request, SellerSettlement $settlement): JsonResponse
    {
        $settlement = $this->settlements->markCollected(
            $settlement,
            $request->user(),
            $request->input('collection_method'),
            $request->input('collection_reference'),
            $request->input('collected_at'),
        );
        $settlement->load(['lot', 'auction', 'vehicle', 'seller', 'adjustments.author']);

        return $this->success(new SellerSettlementResource($settlement));
    }

    /**
     * POST /admin/settlements/{settlement}/adjustments
     * Apply a signed manual adjustment (deduction or credit) with a reason.
     */
    public function applyAdjustment(SettlementAdjustmentRequest $request, SellerSettlement $settlement): JsonResponse
    {
        $settlement = $this->settlements->applyAdjustment(
            $settlement,
            (float) $request->input('amount'),
            (string) $request->input('reason'),
            $request->user(),
        );
        $settlement->load(['lot', 'auction', 'vehicle', 'seller', 'adjustments.author']);

        return $this->success(new SellerSettlementResource($settlement));
    }

    /**
     * GET /admin/settlements/{settlement}/pdf — settlement statement.
     */
    public function pdf(SellerSettlement $settlement): Response
    {
        $settlement->load(['lot', 'auction', 'vehicle', 'seller', 'adjustments']);

        $pdf = Pdf::loadView('settlements.pdf', ['settlement' => $settlement]);

        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"settlement-{$settlement->settlement_number}.pdf\"",
        ]);
    }

    /**
     * GET /admin/settlements/export — CSV of the current filter.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filteredQuery($request)->with(['seller', 'vehicle', 'auction', 'lot']);

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Settlement #', 'Seller Name', 'Seller Email', 'Auction', 'Lot #',
                'Vehicle', 'Outcome', 'Sale Price', 'Registration Fee', 'Commission',
                'No Sale Fee', 'Net Proceeds', 'Status', 'Release Date', 'Check #',
                'Paid At', 'Created At',
            ]);

            $query->orderByDesc('created_at')->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $s) {
                    $vehicle = $s->vehicle
                        ? trim("{$s->vehicle->year} {$s->vehicle->make} {$s->vehicle->model}")
                        : '';

                    fputcsv($handle, [
                        $s->settlement_number,
                        $s->seller?->name ?? '',
                        $s->seller?->email ?? '',
                        $s->auction?->title ?? '',
                        $s->lot?->lot_number ?? '',
                        $vehicle,
                        $s->outcome ?? '',
                        $s->sale_price ?? '',
                        number_format((float) $s->registration_fee, 2),
                        number_format((float) $s->commission_amount, 2),
                        number_format((float) $s->no_sale_fee, 2),
                        number_format((float) $s->net_proceeds, 2),
                        $s->status->value,
                        $s->release_date?->toDateString() ?? '',
                        $s->check_number ?? '',
                        $s->paid_at?->toDateString() ?? '',
                        $s->created_at?->toDateString() ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="seller-settlements-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * Shared filter builder for index + export.
     */
    private function filteredQuery(Request $request)
    {
        $query = SellerSettlement::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($outcome = $request->query('outcome')) {
            $query->where('outcome', $outcome);
        }

        if ($sellerId = $request->query('seller_id')) {
            $query->where('seller_id', $sellerId);
        }

        if ($auctionId = $request->query('auction_id')) {
            $query->where('auction_id', $auctionId);
        }

        // Date range on the settlement creation date.
        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        // Free-text search over seller name/email and settlement number.
        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('settlement_number', 'like', "%{$search}%")
                    ->orWhereHas('seller', function ($sq) use ($search) {
                        $sq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }
}
