<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames the middle vehicle condition light from `blue` to `yellow`.
 *
 * The three lights are presented to bidders as Green / Yellow / Red
 * ("Runs & Drives" / "Runs but has issues" / "Non-Running"), but the column
 * was originally created with a `blue` value, so the stored token disagreed
 * with every label shown in the UI.
 *
 * MySQL cannot rename an enum member in one step while rows still hold the
 * old value, so the enum is first widened to hold both, the rows remapped,
 * then narrowed to the final set. SQLite (used by the test suite) has no real
 * enum — Laravel emits a CHECK constraint instead — so the column is relaxed
 * to a plain string there, mirroring the approach already taken for
 * `users.account_type`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->string('condition_light', 20)->default('green')->change();
            });

            DB::table('vehicles')->where('condition_light', 'blue')->update(['condition_light' => 'yellow']);

            return;
        }

        DB::statement("ALTER TABLE vehicles MODIFY COLUMN condition_light ENUM('green','red','blue','yellow') NOT NULL DEFAULT 'green'");

        DB::table('vehicles')->where('condition_light', 'blue')->update(['condition_light' => 'yellow']);

        DB::statement("ALTER TABLE vehicles MODIFY COLUMN condition_light ENUM('green','yellow','red') NOT NULL DEFAULT 'green'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('vehicles')->where('condition_light', 'yellow')->update(['condition_light' => 'blue']);

            return;
        }

        DB::statement("ALTER TABLE vehicles MODIFY COLUMN condition_light ENUM('green','red','blue','yellow') NOT NULL DEFAULT 'green'");

        DB::table('vehicles')->where('condition_light', 'yellow')->update(['condition_light' => 'blue']);

        DB::statement("ALTER TABLE vehicles MODIFY COLUMN condition_light ENUM('green','red','blue') NOT NULL DEFAULT 'green'");
    }
};
