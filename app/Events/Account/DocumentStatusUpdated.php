<?php

namespace App\Events\Account;

use App\Models\UserDocument;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentStatusUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly UserDocument $document,
    ) {}
}
