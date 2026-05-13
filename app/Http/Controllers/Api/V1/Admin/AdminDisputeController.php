<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DisputeStatus;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDisputeController extends Controller
{
    /**
     * GET /api/v1/admin/disputes
     * Paginated list of all disputes, filterable by status.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Dispute::with([
            'raisedBy:id,name,email',
            'resolvedBy:id,name,email',
            'lot:id,lot_number,auction_id',
            'lot.auction:id,title',
            'bid:id,amount,type',
        ])->orderByDesc('created_at');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        $disputes = $query->paginate($request->integer('per_page', 20));

        $data = $disputes->getCollection()->map(fn ($d) => $this->format($d));

        return $this->success($data, meta: [
            'current_page' => $disputes->currentPage(),
            'last_page'    => $disputes->lastPage(),
            'per_page'     => $disputes->perPage(),
            'total'        => $disputes->total(),
        ]);
    }

    /**
     * GET /api/v1/admin/disputes/{dispute}
     */
    public function show(Dispute $dispute): JsonResponse
    {
        $dispute->load([
            'raisedBy:id,name,email',
            'resolvedBy:id,name,email',
            'lot:id,lot_number,auction_id',
            'lot.auction:id,title,starts_at',
            'bid:id,amount,type,placed_at,is_winning',
        ]);

        return $this->success($this->format($dispute));
    }

    /**
     * POST /api/v1/admin/disputes
     * Admin raises a dispute against a lot (and optionally a specific bid).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'auction_lot_id' => ['required', 'integer', 'exists:auction_lots,id'],
            'bid_id'         => ['nullable', 'integer', 'exists:bids,id'],
            'reason'         => ['required', 'string', 'max:2000'],
        ]);

        $dispute = Dispute::create([
            'auction_lot_id' => $validated['auction_lot_id'],
            'bid_id'         => $validated['bid_id'] ?? null,
            'raised_by'      => $request->user()->id,
            'reason'         => $validated['reason'],
            'status'         => DisputeStatus::Open->value,
        ]);

        $dispute->load([
            'raisedBy:id,name,email',
            'lot:id,lot_number,auction_id',
            'lot.auction:id,title',
            'bid:id,amount,type',
        ]);

        return $this->success($this->format($dispute), 'Dispute raised.', 201);
    }

    /**
     * PATCH /api/v1/admin/disputes/{dispute}
     * Update status and/or admin notes.
     */
    public function update(Request $request, Dispute $dispute): JsonResponse
    {
        $validated = $request->validate([
            'status'      => ['sometimes', 'string', 'in:open,under_review,resolved,dismissed'],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $changes = [];

        if (isset($validated['status'])) {
            $newStatus      = DisputeStatus::from($validated['status']);
            $changes['status'] = $newStatus->value;

            if (in_array($newStatus, [DisputeStatus::Resolved, DisputeStatus::Dismissed])) {
                $changes['resolved_by'] = $request->user()->id;
                $changes['resolved_at'] = now();
            } elseif ($dispute->status->isOpen() === false) {
                $changes['resolved_by'] = null;
                $changes['resolved_at'] = null;
            }
        }

        if (array_key_exists('admin_notes', $validated)) {
            $changes['admin_notes'] = $validated['admin_notes'];
        }

        $dispute->update($changes);
        $dispute->load([
            'raisedBy:id,name,email',
            'resolvedBy:id,name,email',
            'lot:id,lot_number,auction_id',
            'lot.auction:id,title',
            'bid:id,amount,type',
        ]);

        return $this->success($this->format($dispute), 'Dispute updated.');
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function format(Dispute $d): array
    {
        return [
            'id'          => $d->id,
            'status'      => $d->status->value,
            'status_label'=> $d->status->label(),
            'reason'      => $d->reason,
            'admin_notes' => $d->admin_notes,
            'created_at'  => $d->created_at?->toIso8601String(),
            'resolved_at' => $d->resolved_at?->toIso8601String(),
            'raised_by'   => $d->raisedBy ? [
                'id'    => $d->raisedBy->id,
                'name'  => $d->raisedBy->name,
                'email' => $d->raisedBy->email,
            ] : null,
            'resolved_by' => $d->resolvedBy ? [
                'id'    => $d->resolvedBy->id,
                'name'  => $d->resolvedBy->name,
                'email' => $d->resolvedBy->email,
            ] : null,
            'lot' => $d->lot ? [
                'id'         => $d->lot->id,
                'lot_number' => $d->lot->lot_number,
                'auction'    => $d->lot->auction ? [
                    'id'    => $d->lot->auction->id,
                    'title' => $d->lot->auction->title,
                ] : null,
            ] : null,
            'bid' => $d->bid ? [
                'id'     => $d->bid->id,
                'amount' => $d->bid->amount,
                'type'   => $d->bid->type->value,
            ] : null,
        ];
    }
}
