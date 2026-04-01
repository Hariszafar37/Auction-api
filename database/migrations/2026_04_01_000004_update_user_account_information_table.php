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
        // Add id_type ENUM via raw statement (ENUM changes require raw SQL on MySQL)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_account_information ADD COLUMN id_type ENUM(
                'driver_license','state_id','passport'
            ) NULL AFTER driver_license_number");
        }

        Schema::table('user_account_information', function (Blueprint $table) {
            // New generic ID columns — added alongside existing driver_license columns.
            // id_number and id_expiry will eventually replace driver_license_number
            // and driver_license_expiration_date once controllers/models are updated.
            $table->string('id_number', 100)->nullable()->after('id_type');
            $table->date('id_expiry')->nullable()->after('id_number');
            $table->string('id_issuing_state', 100)->nullable()->after('id_expiry');
            $table->string('id_issuing_country', 2)->nullable()->default('US')->after('id_issuing_state');
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
