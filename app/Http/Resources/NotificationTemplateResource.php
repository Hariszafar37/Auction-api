<?php

namespace App\Http\Resources;

use App\Support\FormatsDates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationTemplateResource extends JsonResource
{
    use FormatsDates;

    public function toArray(Request $request): array
    {
        $supported = $this->supported_channels ?? [];

        return [
            'id'                => $this->id,
            'key'               => $this->key,
            'group_key'         => $this->group_key,
            'notification_type' => $this->notification_type,
            'name'              => $this->name,
            'description'       => $this->description,
            'category'          => $this->category,

            'enabled'           => (bool) $this->enabled,
            'email_enabled'     => (bool) $this->email_enabled,
            'in_app_enabled'    => (bool) $this->in_app_enabled,

            // Which toggles the UI may offer at all. A type whose calling code has
            // no mail channel (auction_won, lot_awaiting_seller_decision) must not
            // let an admin switch email on — it would do nothing.
            'supports_email'    => in_array('mail', $supported, true),
            'supports_in_app'   => in_array('database', $supported, true),

            'subject'           => $this->subject,
            'greeting'          => $this->greeting,
            'email_body'        => $this->email_body,
            'action_label'      => $this->action_label,
            'title'             => $this->title,
            'message'           => $this->message,

            'available_variables' => $this->available_variables ?? [],

            'updated_at'        => $this->safeIso($this->updated_at),
            'updated_by'        => $this->whenLoaded('updatedBy', fn () => $this->updatedBy?->name),
        ];
    }
}
