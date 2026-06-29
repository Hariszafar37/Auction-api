<?php

namespace App\Http\Resources\Auction;

use App\Models\AuctionTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuctionTerm
 */
class AuctionTermResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'version'               => $this->version,
            'header'                => $this->header,
            'intro'                 => $this->intro,
            'important_information' => $this->important_information ?? [],
            'full_terms_content'    => $this->full_terms_content,
            'checkbox_label'        => $this->checkbox_label,
            'fees_url'              => $this->fees_url,
            'payment_policy_url'    => $this->payment_policy_url,
            'is_current'            => $this->is_current,
            'updated_at'            => $this->updated_at?->toIso8601String(),
        ];
    }
}
