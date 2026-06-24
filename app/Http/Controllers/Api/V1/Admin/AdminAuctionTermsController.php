<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\UpdateAuctionTermsRequest;
use App\Http\Resources\Auction\AuctionTermAcceptanceResource;
use App\Http\Resources\Auction\AuctionTermResource;
use App\Models\AuctionTerm;
use App\Models\AuctionTermAcceptance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAuctionTermsController extends Controller
{
    /**
     * GET /admin/auction-terms
     * The current master Terms document.
     */
    public function show(): JsonResponse
    {
        return $this->success(new AuctionTermResource(AuctionTerm::current()));
    }

    /**
     * PUT /admin/auction-terms
     * Publish an updated document. Version auto-increments (1.0 → 1.1 → …);
     * users who accepted the prior version must re-accept before re-entering.
     *
     * Guard: a new version is only published when the submitted content differs
     * from the current version — re-submitting identical content is rejected so
     * we never mint a duplicate version (and never force a needless re-accept).
     */
    public function update(UpdateAuctionTermsRequest $request): JsonResponse
    {
        $current   = AuctionTerm::current();
        $validated = $request->validated();

        if (! $this->hasContentChanges($current, $validated)) {
            return $this->error(
                'No changes detected. Please update the Terms & Conditions before publishing a new version.',
                422,
                'no_changes'
            );
        }

        $terms = AuctionTerm::publishUpdate($validated, $request->user()->id);

        return $this->success(
            new AuctionTermResource($terms),
            'Auction Terms updated. Version is now ' . $terms->version . '.'
        );
    }

    /**
     * Whether the submitted payload differs from the current version across any
     * editable content/config field. A missing key means "unchanged" (falls
     * back to the current value), so a partial payload is compared correctly.
     */
    private function hasContentChanges(AuctionTerm $current, array $validated): bool
    {
        $fields = [
            'header', 'intro', 'important_information', 'full_terms_content',
            'checkbox_label', 'fees_url', 'payment_policy_url',
        ];

        foreach ($fields as $field) {
            $new = array_key_exists($field, $validated) ? $validated[$field] : $current->{$field};
            $old = $current->{$field};

            if ($field === 'important_information') {
                if (array_values((array) $new) !== array_values((array) $old)) {
                    return true;
                }
                continue;
            }

            if ($new !== $old) {
                return true;
            }
        }

        return false;
    }

    /**
     * GET /admin/auction-terms/acceptances
     * Paginated acceptance log. Filters: auction_id, version, search (name/email).
     */
    public function acceptances(Request $request): JsonResponse
    {
        $acceptances = $this->logQuery($request)
            ->orderByDesc('accepted_at')
            ->paginate($request->integer('per_page', 20))
            ->appends($request->query());

        return $this->success(
            AuctionTermAcceptanceResource::collection($acceptances),
            meta: [
                'current_page' => $acceptances->currentPage(),
                'last_page'    => $acceptances->lastPage(),
                'per_page'     => $acceptances->perPage(),
                'total'        => $acceptances->total(),
            ]
        );
    }

    /**
     * GET /admin/auction-terms/acceptances/export
     * Memory-safe streaming CSV (Excel-openable) — same pattern as the invoice
     * export: response()->stream() + chunk(500) so memory stays flat.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->logQuery($request)->orderByDesc('accepted_at');

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'User Name', 'Email', 'Role / Account Type',
                'Auction', 'Terms Version', 'IP Address', 'Accepted At',
            ]);

            $query->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    $role = $row->user?->getRoleNames()->first() ?? $row->user?->account_type ?? '';

                    fputcsv($handle, [
                        $row->user?->name ?? '',
                        $row->user?->email ?? '',
                        $role,
                        $row->auction?->title ?? '',
                        $row->terms_version,
                        $row->ip_address ?? '',
                        $row->accepted_at?->toDateTimeString() ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="auction-terms-acceptances-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * Shared filtered query for the log index and the CSV export so both stay
     * in sync.
     */
    private function logQuery(Request $request)
    {
        return AuctionTermAcceptance::query()
            ->with(['user', 'auction'])
            ->when($request->filled('auction_id'), fn ($q) => $q->where('auction_id', $request->integer('auction_id')))
            ->when($request->filled('version'), fn ($q) => $q->where('terms_version', $request->query('version')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->query('search');
                $q->whereHas('user', function ($u) use ($search) {
                    $u->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            });
    }
}
