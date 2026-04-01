<?php

// IMPORTANT: We intentionally do NOT rename driver_license_number or
// driver_license_expiration_date here. Those columns are still referenced
// by the existing ActivationController, UserAccountInformation model, and
// ActivationFlowTest. Renaming them would break the existing codebase.
//
// Instead we ADD the new generic ID columns alongside the old ones so that:
//  1. Existing code continues to write/read driver_license_number as before.
//  2. New code (once models/controllers are updated) can use id_number / id_expiry.
//  3. A follow-up migration can drop the old columns after code is migrated.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Make legacy columns nullable — new code writes to id_number/id_expiry instead.
        // Existing rows written before this migration retain their values.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_account_information MODIFY COLUMN driver_license_number VARCHAR(100) NULL");
            DB::statement("ALTER TABLE user_account_information MODIFY COLUMN driver_license_expiration_date DATE NULL");
        }

        Schema::table('user_account_information', function (Blueprint $table) {
            if (DB::getDriverName() !== 'mysql') {
                // SQLite: use change() to make the legacy columns nullable
                $table->string('driver_license_number', 100)->nullable()->change();
                $table->date('driver_license_expiration_date')->nullable()->change();
            }
        });

        // Add id_type: use MySQL ENUM via raw SQL; fall back to nullable string for SQLite (tests)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_account_information ADD COLUMN id_type ENUM(
                'driver_license','state_id','passport'
            ) NULL AFTER driver_license_number");
        }

        Schema::table('user_account_information', function (Blueprint $table) {
            // New generic ID columns — added alongside existing driver_license columns.
            // id_number and id_expiry will eventually replace driver_license_number
            // and driver_license_expiration_date once controllers/models are updated.
            if (DB::getDriverName() !== 'mysql') {
                // SQLite (used in tests) does not support ENUM; use nullable string instead
                $table->string('id_type', 20)->nullable();
            }
            $table->string('id_number', 100)->nullable();
            $table->date('id_expiry')->nullable();
            $table->string('id_issuing_state', 100)->nullable();
            $table->string('id_issuing_country', 2)->nullable()->default('US');
        });
    }

    public function down(): void
    {
        Schema::table('user_account_information', function (Blueprint $table) {
            $table->dropColumn(['id_number', 'id_expiry', 'id_issuing_state', 'id_issuing_country']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_account_information DROP COLUMN id_type");
        }
    }
};
