<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a customer-facing "fee type" label to ledger adjustments.
 *
 * The internal transaction_type stays 'adjustment' (calculations, filters and
 * reports are unchanged). fee_type is display-only — it titles the adjustment
 * on invoices, the buyer portal, the admin ledger and the PDF (e.g. "Late
 * Payment Fee", "Storage Fee"). Nullable so existing rows keep working and fall
 * back to their reason/notes or a generic label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->string('fee_type', 100)->nullable()->after('transaction_type');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropColumn('fee_type');
        });
    }
};
