<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_lots', function (Blueprint $table) {
            $table->boolean('dealer_only')->default(false)->after('requires_seller_approval');
        });
    }

    public function down(): void
    {
        Schema::table('auction_lots', function (Blueprint $table) {
            $table->dropColumn('dealer_only');
        });
    }
};
