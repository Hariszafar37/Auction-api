<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->text('admin_notes')->nullable();
        });

        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->text('admin_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn('admin_notes');
        });

        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropColumn('admin_notes');
        });
    }
};
