<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FIX 3: offline payment approval audit trail
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->after('processed_by');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('approval_note')->nullable()->after('approved_at');
        });

        // FIX 4: prevent duplicate overdue notification emails
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('overdue_notified_at')->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved_by', 'approved_at', 'approval_note']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('overdue_notified_at');
        });
    }
};
