<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\Approval\ApprovalHistoryResource;
use App\Http\Resources\Admin\Approval\ApprovalRecordResource;
use App\Services\Approval\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals)
    {
    }

    /**
     * GET /api/v1/admin/approvals/dashboard
     *
     * Normalized, filterable, paginated view across all approval types plus summary counts.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $filters = $this->dashboardFilters($request);

        $result    = $this->approvals->dashboard($filters);
        $paginator = $result['records'];

        return $this->success(
            ApprovalRecordResource::collection($paginator),
            meta: [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
                'summary'      => $result['summary'],
            ],
        );
    }

    /**
     * GET /api/v1/admin/approvals/history
     *
     * Global audit-log feed of every recorded approve/reject action.
     */
    public function history(Request $request): JsonResponse
    {
        $paginator = $this->approvals->history([
            'approval_type' => $request->query('approval_type'),
            'action'        => $request->query('action'),
            'performed_by'  => $request->query('performed_by'),
            'date_from'     => $request->query('date_from'),
            'date_to'       => $request->query('date_to'),
            'per_page'      => $request->integer('per_page', 30),
        ]);

        return $this->success(
            ApprovalHistoryResource::collection($paginator),
            meta: [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        );
    }

    /**
     * GET /api/v1/admin/approvals/{type}/{id}/history
     *
     * Action timeline for a single approval record. {id} is the underlying record's
     * primary key (profile id, or power_of_attorney id for POA).
     */
    public function recordHistory(string $type, int $id): JsonResponse
    {
        if (! in_array($type, ApprovalService::TYPES, true)) {
            return $this->error('Unknown approval type.', 422, 'invalid_type');
        }

        $entries = $this->approvals->recordHistory($type, $id);

        if ($entries->isEmpty()) {
            return $this->error('Approval record not found.', 404, 'not_found');
        }

        return $this->success($entries->values());
    }

    /**
     * Extract + light-validate the dashboard filter set.
     *
     * @return array<string,mixed>
     */
    private function dashboardFilters(Request $request): array
    {
        $type   = $request->query('approval_type');
        $status = $request->query('status');

        if ($type && ! in_array($type, [...ApprovalService::TYPES, 'all'], true)) {
            $type = null;
        }
        if ($status && ! in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            $status = null;
        }

        return [
            'approval_type' => $type,
            'status'        => $status,
            'search'        => $request->query('search'),
            'name'          => $request->query('name'),
            'email'         => $request->query('email'),
            'company'       => $request->query('company'),
            'date_from'     => $request->query('date_from'),
            'date_to'       => $request->query('date_to'),
            'action_from'   => $request->query('action_from'),
            'action_to'     => $request->query('action_to'),
            'performed_by'  => $request->query('performed_by'),
            'page'          => $request->integer('page', 1),
            'per_page'      => $request->integer('per_page', 20),
        ];
    }
}
