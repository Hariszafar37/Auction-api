<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_settlements', function (Blueprint $table) {
            // Timestamp a no-sale settlement's outstanding fees were collected
            // from the seller (interim manual workflow; automated deduction is
            // a future phase).
            $table->timestamp('collected_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('seller_settlements', function (Blueprint $table) {
            $table->dropColumn('collected_at');
        });
    }
};
