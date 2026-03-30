<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_dealer_information', function (Blueprint $table) {
            // Nullable for backward compatibility with existing dealer rows.
            // The frontend treats this as required for new registrations.
            $table->string('dealer_state', 100)->nullable()->after('dealer_city');
        });
    }

    public function down(): void
    {
        Schema::table('user_dealer_information', function (Blueprint $table) {
            $table->dropColumn('dealer_state');
        });
    }
};
