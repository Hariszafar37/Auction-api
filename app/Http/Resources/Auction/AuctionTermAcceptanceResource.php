<?php

namespace App\Http\Resources\Auction;

use App\Models\AuctionTermAcceptance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin acceptance-log row. Exposes everything the client's reporting
 * requirement asks for: user name, email, role / account type, acceptance
 * timestamp, IP address, auction name, and terms version.
 *
 * @mixin AuctionTermAcceptance
 */
class AuctionTermAcceptanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'user_id'       => $this->user_id,
            'user_name'     => $this->user?->name,
            'email'         => $this->user?->email,
            'role'          => $this->user?->getRoleNames()->first(),
            'account_type'  => $this->user?->account_type,
            'auction_id'    => $this->auction_id,
            'auction_name'  => $this->auction?->title,
            'terms_version' => $this->terms_version,
            'ip_address'    => $this->ip_address,
            'accepted_at'   => $this->accepted_at?->toIso8601String(),
        ];
    }
}
