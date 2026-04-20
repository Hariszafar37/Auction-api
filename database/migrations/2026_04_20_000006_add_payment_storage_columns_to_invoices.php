<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // FIX 5 — deposit auto-charge tracking
            $table->string('stripe_deposit_intent_id', 200)->nullable()->after('fee_snapshot');
            // pending | authorized | captured | failed | skipped
            $table->string('deposit_status', 30)->default('pending')->after('stripe_deposit_intent_id');

            // FIX 6 — storage accrual tracking
            $table->decimal('storage_fee_total', 10, 2)->default(0)->after('storage_fee_amount');
            $table->timestamp('storage_last_accrued_at')->nullable()->after('storage_fee_total');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_deposit_intent_id',
                'deposit_status',
                'storage_fee_total',
                'storage_last_accrued_at',
            ]);
        });
    }
};
