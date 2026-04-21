<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Payment\InvoicePaymentResource;
use App\Http\Resources\Payment\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Symfony\Component\HttpFoundation\StreamedResponse; // used by response()->stream()

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

        // FIX 3: cancel the deposit hold on Stripe when invoice is voided
        if ($invoice->stripe_deposit_intent_id && $invoice->deposit_status === 'authorized') {
            try {
                $stripe = new StripeClient(config('services.stripe.secret'));
                $stripe->paymentIntents->cancel($invoice->stripe_deposit_intent_id);
                $invoice->update(['deposit_status' => 'cancelled']);
            } catch (\Throwable $e) {
                Log::warning('Failed to cancel deposit PI on void', [
                    'invoice_id' => $invoice->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $invoice->update([
            'status'    => 'void',
            'voided_at' => now(),
            'notes'     => request()->input('notes', $invoice->notes),
        ]);

        return $this->success(new InvoiceResource($invoice->fresh()), 'Invoice voided.');
    }

    // ─── FIX 4: Offline payment approve / reject ─────────────────────────────────

    /**
     * POST /admin/invoices/{invoice}/payments/{payment}/approve
     */
    public function approvePayment(Invoice $invoice, InvoicePayment $payment): JsonResponse
    {
        if ($payment->invoice_id !== $invoice->id) {
            return $this->error('Payment does not belong to this invoice.', 422);
        }

        if ($payment->status !== 'pending_verification') {
            return $this->error('Only payments pending verification can be approved.', 422);
        }

        // FIX 3: capture audit trail — who approved, when, and why
        $note = request()->input('note');

        DB::transaction(function () use ($payment, $invoice, $note) {
            $payment->update([
                'status'        => 'verified',
                'processed_at'  => now(),
                'processed_by'  => request()->user()->id,
                'approved_by'   => request()->user()->id,
                'approved_at'   => now(),
                'approval_note' => $note,
            ]);
            $invoice->recalculateBalance();
        });

        return $this->success(
            new InvoiceResource($invoice->fresh()->load(['lot', 'auction', 'vehicle', 'buyer', 'payments'])),
            'Payment approved.'
        );
    }

    /**
     * POST /admin/invoices/{invoice}/payments/{payment}/reject
     */
    public function rejectPayment(Invoice $invoice, InvoicePayment $payment): JsonResponse
    {
        if ($payment->invoice_id !== $invoice->id) {
            return $this->error('Payment does not belong to this invoice.', 422);
        }

        if ($payment->status !== 'pending_verification') {
            return $this->error('Only payments pending verification can be rejected.', 422);
        }

        $payment->update([
            'status'       => 'rejected',
            'processed_at' => now(),
            'processed_by' => request()->user()->id,
            'notes'        => request()->input('reason', $payment->notes),
        ]);

        return $this->success(
            new InvoicePaymentResource($payment->fresh()),
            'Payment rejected.'
        );
    }

    /**
     * GET /admin/invoices/export
     *
     * FIX 5: memory-safe streaming CSV — flat memory regardless of row count.
     * Uses response()->stream() with chunk(500) so the full result set is never
     * loaded into memory at once.
     */
    public function export(Request $request): StreamedResponse
    {
        $status = $request->query('status');

        return response()->stream(function () use ($status) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Invoice #', 'Buyer Name', 'Buyer Email', 'Lot #',
                'Sale Price', 'Total', 'Balance Due', 'Status',
                'Due Date', 'Paid At', 'Created At',
            ]);

            Invoice::with(['buyer', 'lot'])
                ->when($status, fn ($q) => $q->where('status', $status))
                ->orderBy('created_at', 'desc')
                ->chunk(500, function ($invoices) use ($handle) {
                    foreach ($invoices as $inv) {
                        fputcsv($handle, [
                            $inv->invoice_number,
                            $inv->buyer?->name ?? '',
                            $inv->buyer?->email ?? '',
                            $inv->lot?->lot_number ?? '',
                            $inv->sale_price,
                            number_format((float) $inv->total_amount, 2),
                            number_format((float) $inv->balance_due, 2),
                            $inv->status->value,
                            $inv->due_at?->toDateString() ?? '',
                            $inv->paid_at?->toDateString() ?? '',
                            $inv->created_at?->toDateString() ?? '',
                        ]);
                    }
                });

            fclose($handle);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="invoices-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }
}
