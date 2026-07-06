<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AccountActionResource;
use App\Models\AccountAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAccountActionController extends Controller
{
    /**
     * GET /api/v1/admin/account-actions
     *
     * Global, paginated account-restriction audit report across all users,
     * newest first. Optional filters: user search (name/email), action type,
     * performing admin, and a performed_at date range.
     *
     * Eager-loads subjectUser + performer to avoid N+1 across the page.
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->filteredQuery($request)
            ->with(['subjectUser:id,name,email', 'performer:id,name'])
            ->paginate($request->integer('per_page', 30))
            ->appends($request->query());

        return $this->success(
            AccountActionResource::collection($paginator),
            meta: [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        );
    }

    /**
     * GET /api/v1/admin/account-actions/export
     *
     * Streams the exact filtered dataset (same filters + newest-first ordering
     * as the report) as CSV. Chunked so memory stays flat regardless of size;
     * eager-loads relations per chunk to avoid N+1.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filteredQuery($request)
            ->with(['subjectUser:id,name,email', 'performer:id,name']);

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'User Name', 'User Email', 'Action', 'Previous Value', 'New Value',
                'Reason', 'Performed By', 'IP Address', 'User Agent', 'Date & Time',
            ]);

            $query->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->subjectUser?->name ?? '',
                        $row->subjectUser?->email ?? '',
                        AccountAction::label($row->action),
                        $row->previous_value ?? '',
                        $row->new_value ?? '',
                        $row->reason ?? '',
                        $row->performer?->name ?? '',
                        $row->ip_address ?? '',
                        $row->user_agent ?? '',
                        $row->performed_at?->toDateTimeString() ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="account-restrictions-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * Shared filter + ordering pipeline for the report and its CSV export, so
     * the export always mirrors exactly what the report shows.
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = AccountAction::query()
            ->orderByDesc('performed_at')
            ->orderByDesc('id');

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($performedBy = $request->query('performed_by')) {
            $query->where('performed_by', $performedBy);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->whereHas('subjectUser', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($from = $request->query('date_from')) {
            $query->where('performed_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to = $request->query('date_to')) {
            $query->where('performed_at', '<=', Carbon::parse($to)->endOfDay());
        }

        return $query;
    }
}
