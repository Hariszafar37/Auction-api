<?php

// Backfill account_intent = 'buyer' for all existing individual users
// that were activated before Phase C added auto-setting in ActivationController.
//
// Scope: account_type = 'individual' AND account_intent IS NULL only.
// Dealer users already have account_intent = 'buyer_and_seller' (set by Phase B).
// Business users set their own account_intent via the activation wizard step.
// This migration will NOT touch those rows.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('account_type', 'individual')
            ->whereNull('account_intent')
            ->update(['account_intent' => 'buyer']);
    }

    public function down(): void
    {
        // Reversing a backfill is safe: we only null out rows we set.
        // Any individual user who explicitly had account_intent set before this
        // migration would not have been touched by up(), so we only undo our own writes.
        DB::table('users')
            ->where('account_type', 'individual')
            ->where('account_intent', 'buyer')
            ->update(['account_intent' => null]);
    }
};
