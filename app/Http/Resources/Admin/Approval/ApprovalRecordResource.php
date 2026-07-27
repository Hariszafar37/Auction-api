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
            // Whether `remarks` explains the record's CURRENT status, or is carried
            // over from an earlier decision (e.g. a reason predating an approval).
            'remarks_source' => $r['remarks_source'] ?? null,

            // Every document-review note left for this applicant from the admin
            // user-detail page, newest first. Notes are per-document (ID, dealer
            // license, salesman license…), each rejectable for its own reason.
            // Separate from `remarks` (profile rejection reason) — the two come from
            // different tables and must not be conflated.
            'document_notes'       => $r['document_notes'] ?? [],
            'document_notes_count' => $r['document_notes_count'] ?? 0,
        ];
    }
}
