<?php

namespace App\Http\Resources\Auction;

use App\Support\FormatsDates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BidResource extends JsonResource
{
    use FormatsDates;

    public function toArray(Request $request): array
    {
        $authUser  = $request->user();
        $isMine    = $authUser && $authUser->id === $this->user_id;
        $isAdmin   = $authUser?->hasRole('admin');

        return [
            'id'         => $this->id,
            'amount'     => $this->amount,
            'type'       => $this->type->value,
            'is_winning' => $this->is_winning,
            'placed_at'  => $this->safeIso($this->placed_at),

            // Only show bidder identity to admin or the bidder themselves
            'bidder_id'  => $this->when($isMine || $isAdmin, $this->user_id),
            'is_mine'    => $isMine,
        ];
    }
}
