<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row configuration table for the non-card payment workflow.
 * Admin-editable; consumed by buyer instructions and invoice due-date logic.
 * All operationally-sensitive copy (deadline, acknowledgment, wire details,
 * office hours) lives here so it is never hardcoded in the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();

            // Deadlines & fees
            $table->unsignedTinyInteger('business_days_to_pay')->default(2);
            $table->decimal('late_fee_amount', 10, 2)->default(50);

            // Method availability toggles
            $table->boolean('method_stripe_enabled')->default(true);
            $table->boolean('method_wire_enabled')->default(true);
            $table->boolean('method_cash_enabled')->default(true);
            $table->boolean('method_check_enabled')->default(true);

            // Cash / in-person
            $table->text('payment_location')->nullable();
            $table->text('office_hours')->nullable();

            // Cashier's check
            $table->string('check_payable_to')->nullable();
            $table->text('check_mailing_address')->nullable();
            $table->json('accepted_carriers')->nullable();

            // Wire transfer instructions
            $table->string('wire_beneficiary_name')->nullable();
            $table->string('wire_bank_name')->nullable();
            $table->string('wire_routing_number')->nullable();
            $table->string('wire_account_number')->nullable();
            $table->string('wire_swift_code')->nullable();
            $table->text('wire_business_address')->nullable();
            $table->text('wire_notes')->nullable();

            // Configurable buyer-facing copy
            $table->text('payment_deadline_notice')->nullable();
            $table->text('acknowledgment_text')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
