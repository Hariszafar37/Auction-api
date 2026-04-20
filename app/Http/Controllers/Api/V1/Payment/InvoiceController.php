<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Http\Resources\Payment\InvoiceResource;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    /**
     * GET /my/invoices
     * Returns the authenticated buyer's invoice list.
     */
    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::forBuyer($request->user()->id)
            ->with(['lot', 'auction', 'vehicle'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

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
     * GET /my/invoices/{invoice}
     */
    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->buyer_id !== $request->user()->id) {
            return $this->error('Invoice not found.', 404);
        }

        $invoice->load(['lot', 'auction', 'vehicle', 'payments']);

        return $this->success(new InvoiceResource($invoice));
    }

    /**
     * GET /my/invoices/{invoice}/pdf
     * Streams a PDF copy of the invoice.
     */
    public function pdf(Request $request, Invoice $invoice): Response
    {
        if ($invoice->buyer_id !== $request->user()->id && ! $request->user()->hasRole('admin')) {
            abort(403);
        }

        $invoice->load(['lot', 'auction', 'vehicle', 'buyer', 'payments']);

        $pdf = Pdf::loadView('invoices.pdf', ['invoice' => $invoice]);

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }
}
