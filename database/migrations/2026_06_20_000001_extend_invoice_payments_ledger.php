<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promotes invoice_payments into a permanent, immutable payment ledger.
 *
 * - transaction_type classifies each row (payment/adjustment/reversal/refund/credit/debit).
 * - created_by records who authored the entry (buyer self-report vs. staff).
 * - received_at records when funds physically arrived (admin "mark received").
 * - softDeletes() guarantees ledger rows are never hard-deleted.
 *
 * Existing rows are backfilled to 'payment' so balances stay identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->string('transaction_type', 20)->default('payment')->after('method');
            $table->foreignId('created_by')->nullable()->after('processed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable()->after('processed_at');
            $table->softDeletes();
        });

        // Backfill any pre-existing rows explicitly (default covers new rows).
        DB::table('invoice_payments')->whereNull('transaction_type')->update(['transaction_type' => 'payment']);
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['transaction_type', 'created_by', 'received_at', 'deleted_at']);
        });
    }
};
