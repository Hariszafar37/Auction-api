<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_billing_information', function (Blueprint $table) {
            $table->string('billing_state', 100)->nullable()->after('billing_city');
        });
    }

    public function down(): void
    {
        Schema::table('user_billing_information', function (Blueprint $table) {
            $table->dropColumn('billing_state');
        });
    }
};
