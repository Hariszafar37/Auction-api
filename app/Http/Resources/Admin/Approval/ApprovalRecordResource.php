<?php

namespace App\Http\Resources\Admin\Approval;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a normalized approval record (an associative array produced by ApprovalService).
 *
 * @property-read array<string,mixed> $resource
 */
class ApprovalRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'approval_type'  => $r['approval_type'],
            'related_id'     => $r['related_id'],
            'user_id'        => $r['user_id'],
            'applicant_name' => $r['applicant_name'],
            'email'          => $r['email'],
            'company_name'   => $r['company_name'],
            'identifier'     => $r['identifier'],
            'applied_date'   => $r['applied_date'],
            'status'         => $r['status'],
            'raw_status'     => $r['raw_status'],
            'action_date'    => $r['action_date'],
            'action_by'      => $r['action_by_name'],
            'action_by_id'   => $r['action_by_id'],
            'remarks'        => $r['remarks'],

            // Latest document-review note left for this applicant from the admin
            // user-detail page. Separate from `remarks` (profile rejection reason) —
            // the two come from different tables and must not be conflated.
            'document_remarks'     => $r['document_remarks'] ?? null,
            'document_type'        => $r['document_type'] ?? null,
            'document_status'      => $r['document_status'] ?? null,
            'document_reviewed_at' => $r['document_reviewed_at'] ?? null,
            'document_reviewed_by' => $r['document_reviewed_by'] ?? null,
            'document_notes_count' => $r['document_notes_count'] ?? 0,
        ];
    }
}
