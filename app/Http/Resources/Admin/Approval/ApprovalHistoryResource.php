<?php

namespace App\Http\Resources\Admin\Approval;

use App\Models\ApprovalHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a persisted ApprovalHistory audit row (used by the global history feed).
 *
 * @mixin ApprovalHistory
 */
class ApprovalHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'approval_type'   => $this->approval_type,
            'related_id'      => $this->related_id,
            'subject_user_id' => $this->subject_user_id,
            'applicant_name'  => $this->subjectUser?->name,
            'email'           => $this->subjectUser?->email,
            'action'          => $this->action,
            'previous_status' => $this->previous_status,
            'new_status'      => $this->new_status,
            'remarks'         => $this->remarks,
            'performed_by'    => $this->performed_by,
            'performed_by_name' => $this->performer?->name,
            'performed_at'    => optional($this->performed_at)->toIso8601String(),
        ];
    }
}
