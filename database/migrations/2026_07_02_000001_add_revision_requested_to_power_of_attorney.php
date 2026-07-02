<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a 'revision_requested' status to the POA lifecycle.
 *
 * The original `power_of_attorney.status` column was created as an enum limited
 * to ('pending','signed','approved','rejected'). To support the admin
 * "Request Revision" action (mirroring the user_documents `needs_resubmission`
 * loop) we convert the column to a plain string on every driver.
 *
 * Converting to string rather than extending the enum keeps MySQL and the
 * SQLite test environment in lock-step — SQLite cannot ALTER an enum CHECK
 * constraint in-place, so an enum-only change would silently reject the new
 * value in tests (same rationale as the account_type sqlite-compat migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('power_of_attorney', function (Blueprint $table) {
            $table->string('status', 30)->default('pending')->change();
        });
    }

    public function down(): void
    {
        // No-op: reverting to the restrictive enum would break any rows already
        // carrying the 'revision_requested' value and is unnecessary.
    }
};
