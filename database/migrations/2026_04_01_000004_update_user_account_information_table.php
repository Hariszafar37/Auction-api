<?php

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
            $table->renameColumn('driver_license_number', 'id_number');
            $table->renameColumn('driver_license_expiration_date', 'id_expiry');
            $table->string('id_issuing_state', 100)->nullable()->after('id_type');
            $table->string('id_issuing_country', 2)->nullable()->default('US')->after('id_issuing_state');
        });
    }

    public function down(): void
    {
        Schema::table('user_account_information', function (Blueprint $table) {
            $table->dropColumn(['id_issuing_state', 'id_issuing_country']);
            $table->renameColumn('id_number', 'driver_license_number');
            $table->renameColumn('id_expiry', 'driver_license_expiration_date');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_account_information DROP COLUMN id_type");
        }
    }
};
