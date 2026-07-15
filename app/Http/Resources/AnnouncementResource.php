<?php

namespace App\Http\Resources;

use App\Support\AnnouncementAudience;
use App\Support\FormatsDates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementResource extends JsonResource
{
    use FormatsDates;

    public function toArray(Request $request): array
    {
        $audience = $this->audience ?? ['type' => 'all'];

        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'description'       => $this->description,

            'enabled'           => (bool) $this->enabled,
            'email_enabled'     => (bool) $this->email_enabled,
            'in_app_enabled'    => (bool) $this->in_app_enabled,

            'subject'           => $this->subject,
            'greeting'          => $this->greeting,
            'email_body'        => $this->email_body,
            'title'             => $this->title,
            'message'           => $this->message,

            'available_variables' => $this->available_variables ?? [],

            'audience'          => $audience,
            'audience_label'    => AnnouncementAudience::describe($audience),
            // Only computed on the single-resource (show) response, where the extra
            // count query is cheap; the list view must not fire one query per row.
            'recipient_count'   => $this->when(
                $request->routeIs('*.announcements.show'),
                fn () => AnnouncementAudience::count($audience),
            ),

            'status'            => $this->sent_at ? 'sent' : 'draft',
            'sent_at'           => $this->safeIso($this->sent_at),
            'updated_at'        => $this->safeIso($this->updated_at),
            'updated_by'        => $this->whenLoaded('updatedBy', fn () => $this->updatedBy?->name),
        ];
    }
}
