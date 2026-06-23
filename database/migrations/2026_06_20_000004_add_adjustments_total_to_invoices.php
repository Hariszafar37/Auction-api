<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache column for the signed sum of verified charge-side ledger entries
 * (late fees +, discounts −). Lets effective_total be cheap column math
 * instead of an N+1 ledger query on every serialization — recalculateBalance()
 * keeps it in sync, mirroring the existing balance_due cache pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('adjustments_total', 10, 2)->default(0)->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('adjustments_total');
        });
    }
};
