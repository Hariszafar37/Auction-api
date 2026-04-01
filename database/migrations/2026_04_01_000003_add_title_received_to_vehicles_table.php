<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->boolean('title_received')->default(false)->after('has_title');
            $table->timestamp('title_received_at')->nullable()->after('title_received');
            $table->unsignedBigInteger('title_received_by')->nullable()->after('title_received_at');
            $table->foreign('title_received_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['title_received_by']);
            $table->dropColumn(['title_received', 'title_received_at', 'title_received_by']);
        });
    }
};
