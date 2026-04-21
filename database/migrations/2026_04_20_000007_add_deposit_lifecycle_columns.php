<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FIX 3: deposit lifecycle tracking
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('deposit_captured_at')->nullable()->after('deposit_status');
        });

        // FIX 2: prevent duplicate InvoicePayment records for the same Stripe PI
        // MySQL UNIQUE allows multiple NULLs, so offline payments (no reference) are unaffected.
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->unique('reference', 'invoice_payments_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropUnique('invoice_payments_reference_unique');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('deposit_captured_at');
        });
    }
};
