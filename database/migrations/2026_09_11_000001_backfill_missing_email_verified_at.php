<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs accounts left with a NULL `email_verified_at` by mass assignment.
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
 * The same drop hit `AdminUserController::store()` and `AdminUserSeeder`, so a
 * second pass repairs admin-created accounts. Each pass takes its timestamp from
 * the row itself rather than `now()`, so the repaired history stays truthful.
 *
 * Both passes are deliberately narrow, and neither invents a verification that
 * did not happen: a row is only touched when something already on it proves the
 * address was reached. Accounts with nothing to vouch for them — most notably a
 * government account activated while its invitation was still unaccepted — are
 * left alone, and recover the proper way, by an admin resending the invite.
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

        // Second pass: accounts created through the admin console
        // (AdminUserController::store, AdminUserSeeder), which dropped the same
        // column the same way. These are active and hold a password, so they are
        // fully onboarded — only the timestamp is missing.
        //
        // `password_set_at` is the discriminator that keeps this honest. A
        // password can only be set after verification (setPassword() gates on
        // hasVerifiedEmail()) or by an admin creating the account outright, so
        // its presence means the address is already trusted. Requiring it also
        // excludes the genuinely-unproven case this must not touch: a government
        // account an admin activated while its invitation was still unaccepted —
        // no password, no acceptance, so nothing establishes the address.
        //
        // `created_at` is used as the timestamp: it is the moment the admin
        // vouched for the address, and it keeps the row internally consistent
        // rather than stamping a verification that appears to postdate activation.
        DB::table('users')
            ->whereNull('email_verified_at')
            ->where('status', 'active')
            ->whereNotNull('password_set_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Intentionally irreversible. Rolling back would re-strand the very
        // accounts this repairs, and the pre-migration NULL is not recoverable
        // from any surviving column once it has been filled.
    }
};
