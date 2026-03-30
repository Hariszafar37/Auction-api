<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend account_type enum to include business and government (MySQL only)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN account_type
                ENUM('individual','dealer','business','government') NULL");
        }

        Schema::table('users', function (Blueprint $table) {
            // account_intent: nullable so existing individual/dealer rows stay valid
            $table->enum('account_intent', ['buyer', 'seller', 'buyer_and_seller'])
                  ->nullable()
                  ->after('account_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('account_intent');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN account_type
                ENUM('individual','dealer') NULL");
        }
    }
};
