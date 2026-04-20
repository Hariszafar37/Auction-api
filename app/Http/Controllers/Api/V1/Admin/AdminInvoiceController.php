<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Payment\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminInvoiceController extends Controller
{
    /**
     * GET /admin/invoices
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with(['lot', 'auction', 'vehicle', 'buyer'])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($buyerId = $request->query('buyer_id')) {
            $query->where('buyer_id', $buyerId);
        }

        $invoices = $query->paginate($request->integer('per_page', 20));

        return $this->success(
            InvoiceResource::collection($invoices),
            meta: [
                'current_page' => $invoices->currentPage(),
                'last_page'    => $invoices->lastPage(),
                'per_page'     => $invoices->perPage(),
                'total'        => $invoices->total(),
            ]
        );
    }

    /**
     * GET /admin/invoices/{invoice}
     */
    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load(['lot', 'auction', 'vehicle', 'buyer', 'payments']);
        return $this->success(new InvoiceResource($invoice));
    }

    /**
     * POST /admin/invoices/{invoice}/void
     */
    public function void(Invoice $invoice): JsonResponse
    {
        if ($invoice->isPaid()) {
            return $this->error('Paid invoices cannot be voided.', 422, 'invoice_paid');
        }

        $invoice->update([
            'status'     => 'void',
            'voided_at'  => now(),
            'notes'      => request()->input('notes', $invoice->notes),
        ]);

        return $this->success(new InvoiceResource($invoice->fresh()), 'Invoice voided.');
    }
}
