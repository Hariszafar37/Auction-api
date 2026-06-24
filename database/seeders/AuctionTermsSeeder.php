<?php

namespace Database\Seeders;

use App\Models\AuctionTerm;
use Illuminate\Database\Seeder;

class AuctionTermsSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent: creates the v1.0 master Terms document (from the client's
        // breakdown doc) if none exists; leaves any admin edits untouched.
        AuctionTerm::current();
    }
}
