<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite test-environment compatibility migration.
 *
 * The original `users.account_type` column was created as an enum with a
 * CHECK constraint limited to ('individual','dealer'). SQLite does not support
 * ALTER TABLE for enum changes, so the constraint persists even after the
 * MySQL-only ALTER in the subsequent migration.
 *
 * This migration converts the column to a plain string on SQLite so that
 * 'business' and 'government' values are accepted in tests.
 * MySQL production is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        // SQLite does not support modifying column constraints in-place.
        // Recreate the table structure without the restrictive CHECK constraint.
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_type', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        // No-op: reverting would re-add the CHECK constraint which is not
        // necessary for test environments.
    }
};
