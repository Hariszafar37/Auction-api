<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs government accounts stranded by the invite-acceptance bug.
 *
 * `AuthController::acceptInvite()` passed `email_verified_at` through
 * `User::update()`, but that column is not in `User::$fillable`, so Eloquent
 * dropped it silently. The sibling `status` key *is* fillable, which left these
 * accounts in an impossible state: `status = pending_password` (the holder
 * demonstrably clicked the emailed invite link) with `email_verified_at = NULL`.
 *
 * The set-password endpoint then rejected them with `email_not_verified`, and
 * because acceptance also clears `gov_profiles.invite_token`, the link could not
 * be replayed and "resend invitation" refuses an already-accepted invite — so
 * the account was permanently unusable.
 *
 * Acceptance is only reachable by following a tokened link sent to the address
 * itself, so `invite_accepted_at` is the moment the address was proven; it is
 * used as the verification timestamp rather than `now()`.
 *
 * Scope is deliberately narrow — only rows that carry an acceptance timestamp
 * and are still missing the verification one. Accounts that never accepted are
 * left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stranded = DB::table('users')
            ->join('gov_profiles', 'gov_profiles.user_id', '=', 'users.id')
            ->whereNull('users.email_verified_at')
            ->whereNotNull('gov_profiles.invite_accepted_at')
            ->pluck('gov_profiles.invite_accepted_at', 'users.id');

        foreach ($stranded as $userId => $acceptedAt) {
            DB::table('users')
                ->where('id', $userId)
                ->update(['email_verified_at' => $acceptedAt]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible. Rolling back would re-strand the very
        // accounts this repairs, and the pre-migration NULL is not recoverable
        // from any surviving column once it has been filled.
    }
};
