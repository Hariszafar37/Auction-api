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
        ];
    }
}
