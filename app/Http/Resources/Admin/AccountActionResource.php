<?php

namespace App\Http\Resources\Admin;

use App\Models\AccountAction;
use App\Support\FormatsDates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-only view of a single account-restriction audit row.
 *
 * Expects the `performer` relation to be eager-loaded by the caller so the
 * list endpoint stays free of N+1 queries.
 */
class AccountActionResource extends JsonResource
{
    use FormatsDates;

    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'action'         => $this->action,
            // Canonical human-friendly label (kept in sync with the frontend map).
            'action_label'   => AccountAction::label($this->action),
            'previous_value' => $this->previous_value,
            'new_value'      => $this->new_value,
            'reason'         => $this->reason,
            // Performer may be null (system action or a since-deleted admin).
            'performed_by'    => $this->performer?->name,
            'performed_by_id' => $this->performer?->id,
            'ip_address'      => $this->ip_address,
            'user_agent'      => $this->user_agent,
            'performed_at'    => $this->safeIso($this->performed_at),

            // Affected user — included only on the global report (where the
            // subjectUser relation is eager-loaded); omitted on the per-user
            // endpoint since the user is already the page context.
            'user'           => $this->whenLoaded('subjectUser', fn () => $this->subjectUser ? [
                'id'    => $this->subjectUser->id,
                'name'  => $this->subjectUser->name,
                'email' => $this->subjectUser->email,
            ] : null),
        ];
    }
}
