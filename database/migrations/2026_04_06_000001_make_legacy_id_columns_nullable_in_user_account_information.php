<?php

// This migration fixes a silent failure from 2026_04_01_000004.
//
// That migration attempted to make driver_license_number and
// driver_license_expiration_date nullable via raw DB::statement() for MySQL.
// The migration was recorded as "Ran" in the migrations table, but the
// DB::statement() ALTER TABLE calls failed silently — the columns remained
// NOT NULL without a default. This caused MySQL error 1364 ("Field doesn't
// have a default value") when ActivationController::accountInformation()
// submitted the new generic ID payload (id_type / id_number / id_expiry)
// without these legacy columns.
//
// Fix: use Schema Blueprint change() which is reliable for both MySQL and
// SQLite. The Blueprint approach is the correct Laravel 11 native approach.
//
// No meaningful down() — reverting to NOT NULL is destructive if any rows
// already have NULLs in these columns.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // For MySQL: check actual nullability before attempting change(),
            // making this migration safely idempotent on repeated runs.
            $cols = collect(DB::select("SHOW COLUMNS FROM user_account_information"))
                ->keyBy('Field');

            $licenseNullable    = isset($cols['driver_license_number']) && $cols['driver_license_number']->Null === 'YES';
            $expiryNullable     = isset($cols['driver_license_expiration_date']) && $cols['driver_license_expiration_date']->Null === 'YES';

            if ($licenseNullable && $expiryNullable) {
                return; // Already nullable — nothing to do
            }

            Schema::table('user_account_information', function (Blueprint $table) use ($licenseNullable, $expiryNullable) {
                if (! $licenseNullable) {
                    $table->string('driver_license_number', 100)->nullable()->change();
                }
                if (! $expiryNullable) {
                    $table->date('driver_license_expiration_date')->nullable()->change();
                }
            });
        } else {
            // SQLite (test environment): change() is idempotent for nullable columns.
            Schema::table('user_account_information', function (Blueprint $table) {
                $table->string('driver_license_number', 100)->nullable()->change();
                $table->date('driver_license_expiration_date')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: reverting these to NOT NULL is destructive
        // if any rows have been inserted without driver_license values.
    }
};
