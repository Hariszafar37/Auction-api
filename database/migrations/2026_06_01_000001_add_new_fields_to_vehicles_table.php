<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('condition_report_url', 2048)->nullable()->after('condition_notes');
            $table->longText('additional_info')->nullable()->after('condition_report_url');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['condition_report_url', 'additional_info']);
        });
    }
};
