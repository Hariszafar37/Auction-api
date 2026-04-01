<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_dealer_information', function (Blueprint $table) {
            $table->string('dba_name', 255)->nullable();
            $table->string('office_phone', 30)->nullable();
            if (DB::getDriverName() !== 'mysql') {
                // SQLite (used in tests) does not support ENUM; use nullable string instead
                $table->string('dealer_type', 20)->nullable();
            }
            $table->string('salesman_name', 255)->nullable();
            $table->string('salesman_license_number', 100)->nullable();
            $table->string('salesman_license_state', 100)->nullable();
            $table->date('salesman_license_expiry')->nullable();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_dealer_information ADD COLUMN dealer_type ENUM(
                'retail','wholesale'
            ) NULL AFTER office_phone");
        }
    }

    public function down(): void
    {
        Schema::table('user_dealer_information', function (Blueprint $table) {
            $table->dropColumn([
                'dba_name',
                'office_phone',
                'salesman_name',
                'salesman_license_number',
                'salesman_license_state',
                'salesman_license_expiry',
            ]);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_dealer_information DROP COLUMN dealer_type");
        }
    }
};
