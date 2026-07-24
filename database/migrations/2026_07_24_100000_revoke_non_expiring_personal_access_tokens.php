<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Forces every pre-existing session to re-authenticate under the new policy.
 *
 * Every token issued before the token-expiry policy landed was created with
 * expires_at = NULL, i.e. valid forever. Those are precisely the sessions the
 * policy exists to close — leaving them in place would mean every currently
 * logged-in user (admins included) keeps a permanent, non-expiring credential.
 *
 * Deleting the rows simply prompts affected users to log in again; no other
 * data is touched. Delivered as a migration rather than an artisan command so
 * it runs exactly once per environment as part of the normal deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('personal_access_tokens')->delete();
    }

    public function down(): void
    {
        // Revoked tokens are unrecoverable by design — re-authentication is the
        // only path back, and that is the intended outcome of rolling back too.
    }
};
